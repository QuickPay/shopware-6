<?php declare(strict_types=1);

namespace Wexo\Quickpay\Service;

use Exception;
use GuzzleHttp\Client;
use Monolog\Logger;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
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
use TypeError;
use Wexo\Quickpay\WexoQuickpay;

/**
 * Class QuickpayPayment
 * @package Wexo\Quickpay\Service
 */
class QuickpayPayment implements AsynchronousPaymentHandlerInterface
{
    private SystemConfigService $systemConfigService;
    protected EntityRepositoryInterface $logEntryRepository;
    private EntityRepositoryInterface $orderRepository;
    private OrderTransactionStateHandler $transactionStateHandler;
    public Client $http;

    /**
     * QuickpayPayment constructor.
     * @param SystemConfigService $systemConfigService
     * @param EntityRepositoryInterface $logEntryRepository
     * @param EntityRepositoryInterface $orderRepository
     * @param OrderTransactionStateHandler $transactionStateHandler
     */
    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepositoryInterface $logEntryRepository,
        EntityRepositoryInterface $orderRepository,
        OrderTransactionStateHandler $transactionStateHandler
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->logEntryRepository = $logEntryRepository;
        $this->orderRepository = $orderRepository;
        $this->transactionStateHandler = $transactionStateHandler;

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

            $response = $this->createPayment(
                [
                    'currency' => $currency,
                    'order_id' => $orderNumber
                ],
                $order->getLineItems(),
                $order->getAmountTotal(),
                $transaction->getReturnUrl(),
                $mobilepayId === $paymentOptionId
            );

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

        // TODO: Hmm, correct way to check if invalid?

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
            $this->transactionStateHandler->pay($transaction->getOrderTransaction()->getId(), $context);
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
     * @return array
     * @throws Exception
     */
    public function createPayment(
        array $formParams,
        $orderLineItems,
        float $amount,
        string $callbackUrl,
        bool $isMobilepay = false
    ) {
        foreach ($orderLineItems as $orderLineItem) {
            $payload = $orderLineItem->getPayload();
            $itemNo = ($payload && isset($payload['productNumber']))
                ? $payload['productNumber']
                : $orderLineItem->getReferencedId();
            $formParams['basket[][qty]'] = $orderLineItem->getQuantity();
            $formParams['basket[][item_no]'] = $itemNo;
            $formParams['basket[][item_name]'] = $orderLineItem->getLabel();
            $formParams['basket[][item_price]'] = (int)$orderLineItem->getUnitPrice();
            $formParams['basket[][vat_rate]'] = $orderLineItem->getPrice()->getTaxRules()->first()->getTaxRate() / 100;
        }

        $createResponse = $this->http->request('POST', 'payments', [
            'form_params' => $formParams
        ]);

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
}
