<?php declare(strict_types=1);

namespace Wexo\Quickpay\Service;

use Exception;
use GuzzleHttp\Client;
use Monolog\Logger;
use Shopware\Core\Checkout\Cart\CartPersisterInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use TypeError;
use Wexo\Quickpay\WexoQuickpay;

/**
 * Class QuickpayPayment
 * @package Wexo\Quickpay\Service
 */
class QuickpayPayment implements AsynchronousPaymentHandlerInterface
{
    /** @var SystemConfigService $systemConfigService */
    private $systemConfigService;
    /** @var EntityRepositoryInterface $orderRepository */
    private $orderRepository;
    /** @var OrderTransactionStateHandler $transactionStateHandler */
    private $transactionStateHandler;
    /** @var OrderService $orderService */
    private $orderService;
    /** @var EntityRepositoryInterface $logEntryRepository */
    protected $logEntryRepository;
    /** @var Client $http */
    public $http;
    /** @var CartPersisterInterface */
    protected $cartPersister;

    /**
     * QuickpayPayment constructor.
     * @param SystemConfigService $systemConfigService
     * @param EntityRepositoryInterface $logEntryRepository
     * @param EntityRepositoryInterface $orderRepository
     * @param OrderTransactionStateHandler $transactionStateHandler
     * @param OrderService $orderService
     */
    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepositoryInterface $logEntryRepository,
        EntityRepositoryInterface $orderRepository,
        OrderTransactionStateHandler $transactionStateHandler,
        OrderService $orderService,
        CartPersisterInterface $cartPersister
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->logEntryRepository = $logEntryRepository;
        $this->orderRepository = $orderRepository;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->orderService = $orderService;
        $this->cartPersister = $cartPersister;

        $this->http = new Client([
            'base_uri' => 'https://api.quickpay.net/',
            'headers' => [
                "Accept" => "*/*",
                "Accept-Encoding" => "gzip, deflate",
                "Accept-Version" => "v10",
                "Connection" => "keep-alive",
                "Host" => "api.quickpay.net",
                "cache-control" => "no-cache"
            ],
            'auth' => [
                '',
                $this->systemConfigService->get('WexoQuickpay.config.quickpayApiKey')
            ],
        ]);
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @return RedirectResponse
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        // Method that sends the return URL to the external gateway and gets a redirect URL back
        try {
            $order = $transaction->getOrder();
            $paymentOptionId = $order->getTransactions()->first()->getPaymentMethod()->getId();
            $mobilepayId = $this->systemConfigService->get('WexoQuickpay.config.mobilepayId');
            $orderNumber = $order->getOrderNumber();

            $currency = $salesChannelContext->getCurrency()->getIsoCode()
                ?? WexoQuickpay::FALLBACK_CURRENCY;

            try {
                $response = $this->createPayment(
                    [
                        'currency' => $currency,
                        'order_id' => $orderNumber
                    ],
                    $order->getLineItems(),
                    $order->getAmountTotal(),
                    $transaction->getReturnUrl(),
                    $mobilepayId === $paymentOptionId,
                    $order->getShippingTotal(),
                    $order->getShippingCosts()->getTaxRules()->first()->getTaxRate()
                );
            } catch (ServiceUnavailableHttpException $exception) {
                return new RedirectResponse('/', 503);
            }

            $customFields = $order->getCustomFields();
            $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD] = json_encode($response['response']);

            $this->orderRepository->update(
                [
                    [
                        'id' => $order->getId(),
                        'customFields' => $customFields
                    ]
                ],
                $salesChannelContext->getContext()
            );
        } catch (\Error | TypeError | Exception $e) {
            $this->paymentLogger(
                WexoQuickpay::ORDER_CREATE_ERROR,
                [
                    'orderId' => $order->getId() ?? null,
                    'orderNumber' => $order->getOrderNumber() ?? null,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'errorType' => get_class($e)
                ]
            );

            throw new AsyncPaymentProcessException(
                $transaction->getOrder()->getId(),
                'An error occurred during the communication with external payment gateway' . PHP_EOL .
                $e->getMessage()
            );
        }

        // Check if gateway link got created, if not, mark order as canceled
        if ($response['link'] == null) {
            $this->transactionStateHandler->cancel(
                $transaction->getOrderTransaction()->getId(),
                $salesChannelContext->getContext()
            );

            return new RedirectResponse("/");
        }
        // Redirect to external gateway
        return new RedirectResponse($response['link']);
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     */
    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        $transactionId = $transaction->getOrderTransaction()->getId();

        $context = $salesChannelContext->getContext();
        if ($request->get('status') == "accepted") {
            // Payment completed
            $this->cartPersister->delete($salesChannelContext->getToken(), $salesChannelContext);
            $this->transactionStateHandler->paid($transaction->getOrderTransaction()->getId(), $context);
        } elseif ($request->get('status') == "cancel") {
            throw new CustomerCanceledAsyncPaymentException(
                $transactionId,
                'Customer canceled the payment on the payment page'
            );
        }
    }

    /**
     * @param array $formParams
     * @param OrderLineItemCollection $orderLineItems
     * @param float $amount
     * @param string $callbackUrl
     * @param bool $isMobilepay
     * @param float $shippingTotal
     * @param float $shippingTaxes
     * @return array
     * @throws Exception
     */
    public function createPayment(
        array $formParams,
        $orderLineItems,
        float $amount,
        string $callbackUrl,
        bool $isMobilepay = false,
        float $shippingTotal = 0,
        float $shippingTaxes = 0
    ) {
        $formParams['basket'] = [];
        foreach ($orderLineItems as $orderLineItem) {
            $payload = $orderLineItem->getPayload();
            $itemNo = ($payload && isset($payload['productNumber']))
                ? $payload['productNumber']
                : $orderLineItem->getLabel();
            $formParams['basket'][] = [
                'qty' => (int)$orderLineItem->getQuantity(),
                'item_no' => $itemNo,
                'item_name' => $orderLineItem->getLabel(),
                'item_price' => $orderLineItem->getUnitPrice(),
                'vat_rate' => $orderLineItem->getPrice()->getTaxRules()->first()->getTaxRate() / 100,
            ];
        }

        if ($shippingTotal and $shippingTaxes) {
            $formParams['basket'][] = [
                'qty' => 1,
                'item_no' => 'Shipping',
                'item_name' => 'Shipping',
                'item_price' => $shippingTotal,
                'vat_rate' => $shippingTaxes / 100,
            ];
        }

        try {
            $createResponse = $this->http->request('POST', 'payments', [
                'json' => $formParams
            ]);
        } catch (\Exception $e) {
            $this->paymentLogger(
                WexoQuickpay::ORDER_CREATE_ERROR,
                [
                    'formParams' => $formParams,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'errorType' => get_class($e)
                ]
            );

            throw new ServiceUnavailableHttpException();
        }

        if ($createResponse->getStatusCode() !== 201) {
            throw new Exception(
                $createResponse->getBody()->getContents()
                ?? 'Failed to create payment for order '
                . $formParams['order_id'] ?? null
            );
        }

        $createContent = json_decode($createResponse->getBody()->getContents());

        try {
            $updateFormParams = [
                'amount' => $amount * 100,
                'continue_url' => $callbackUrl . '&status=accepted',
                'cancel_url' => $callbackUrl . '&status=cancel'
            ];

            if ($isMobilepay) {
                $updateFormParams['acquirer'] = 'mobilepay';
            }

            $responseUpdateLink = $this->http->request('put', 'payments/' . $createContent->id . "/link", [
                'form_params' => $updateFormParams
            ]);

            if ($responseUpdateLink->getStatusCode() !== 200) {
                throw new Exception(
                    $responseUpdateLink->getBody()->getContents()
                    ?? 'Failed to link payment for order '
                    . $formParams['order_id'] ?? null
                );
            }

            $updateLink = json_decode($responseUpdateLink->getBody()->getContents())->url;

            $this->paymentLogger(
                WexoQuickpay::ORDER_CREATE_SUCCESS,
                [
                    'orderId' => $formParams['order_id'],
                    'formParams' => $formParams,
                    'updateFormParams' => $updateFormParams
                ],
                Logger::INFO
            );
        } catch (\Error | TypeError | Exception $e) {
            $this->paymentLogger(
                WexoQuickpay::ORDER_CREATE_ERROR,
                [
                    'formParams' => $formParams,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'errorType' => get_class($e)
                ]
            );
        }

        return [
            'response' => $createContent,
            'link' => $updateLink ?? null
        ];
    }

    /**
     * @param string $event
     * @param array $context
     * @param int $level
     */
    public function paymentLogger(
        string $event,
        array $context,
        int $level = Logger::ERROR
    ) {
        $this->logEntryRepository->create(
            [
                [
                    'message' => $event,
                    'context' => $context,
                    'level' => $level,
                    'channel' => WexoQuickpay::LOG_CHANNEL
                ]
            ],
            Context::createDefaultContext()
        );
    }

    /**
     *
     * @param array $options [description]
     *
     * @param array $config [Quickpay config]
     * @option string "quickpayApiKey" [Quickpay Api Key]
     * @option string "mobilepayId" [Mobilepay id]
     * @return bool
     */
    public function isConfigValid(array $config): bool
    {
        try {
            $response = $this->http->request('GET', 'payments', [
                'auth' => [
                    '',
                    $config['quickpayApiKey']
                ]
            ]);

            return $response->getStatusCode() === 200;
        } catch (\Exception $exception) {
            $this->paymentLogger($exception->getMessage(), $exception->getTrace());

            return false;
        }
    }
}
