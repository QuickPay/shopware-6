<?php declare(strict_types=1);

namespace Wexo\Quickpay\Service;

use Exception;
use GuzzleHttp\Client;
use Monolog\Logger;
use Shopware\Core\Checkout\Cart\CartPersisterInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\System\StateMachine\Exception\StateMachineInvalidEntityIdException;
use Shopware\Core\System\StateMachine\Exception\StateMachineInvalidStateFieldException;
use Shopware\Core\System\StateMachine\Exception\StateMachineNotFoundException;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\ParameterBag;
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
    /**
     * @var SystemConfigService $systemConfigService
     */
    protected $systemConfigService;
    /**
     * @var EntityRepositoryInterface $orderRepository
     */
    protected $orderRepository;
    /**
     * @var OrderTransactionStateHandler $transactionStateHandler
     */
    protected $transactionStateHandler;
    /**
     * @var OrderService $orderService
     */
    protected $orderService;
    /**
     * @var EntityRepositoryInterface $logEntryRepository
     */
    protected $logEntryRepository;
    /**
     * @var Client $http
     */
    public $http;
    /**
     * @var CartPersisterInterface
     */
    protected $cartPersister;
    /**
     * @var StateMachineRegistry
     */
    protected $stateMachineRegistry;

    /**
     * QuickpayPayment constructor.
     * @param SystemConfigService $systemConfigService
     * @param EntityRepositoryInterface $logEntryRepository
     * @param EntityRepositoryInterface $orderRepository
     * @param OrderTransactionStateHandler $transactionStateHandler
     * @param OrderService $orderService
     * @param CartPersisterInterface $cartPersister
     * @param StateMachineRegistry $stateMachineRegistry
     */
    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepositoryInterface $logEntryRepository,
        EntityRepositoryInterface $orderRepository,
        OrderTransactionStateHandler $transactionStateHandler,
        OrderService $orderService,
        CartPersisterInterface $cartPersister,
        StateMachineRegistry $stateMachineRegistry
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->logEntryRepository = $logEntryRepository;
        $this->orderRepository = $orderRepository;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->orderService = $orderService;
        $this->cartPersister = $cartPersister;
        $this->stateMachineRegistry = $stateMachineRegistry;
    }

    /**
     * @param SalesChannelContext|null $salesChannelContext
     */
    public function initClient(?SalesChannelContext $salesChannelContext = null)
    {
        if (! $this->http) {
            if ($salesChannelContext) {
                $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
                $apiKey = $this->systemConfigService->get('WexoQuickpay.config.quickpayApiKey', $salesChannelId);
            } else {
                $apiKey = $this->systemConfigService->get('WexoQuickpay.config.quickpayApiKey');
            }

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
                    $apiKey
                ],
            ]);
        }
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
            $this->initClient($salesChannelContext);
            $order = $transaction->getOrder();
            $paymentHandler = $order->getTransactions()->first()->getPaymentMethod()->getHandlerIdentifier();
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
                    $paymentHandler,
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
            $this->stateMachineRegistry->transition(
                new Transition(
                    OrderTransactionDefinition::ENTITY_NAME,
                    $transactionId,
                    StateMachineTransitionActions::ACTION_AUTHORIZE,
                    'stateId'
                ),
                $context
            );

            $this->orderService->orderStateTransition(
                $transaction->getOrder()->getId(),
                StateMachineTransitionActions::ACTION_PROCESS,
                new ParameterBag(),
                $context
            );

            $customFields = $transaction->getOrder()->getCustomFields();
            if ($customFields && isset($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD])) {
                $data = json_decode($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD]);
                if (property_exists($data, 'id')) {
                    $this->updateResponse($transaction->getOrder()->getId(), $data->id);
                }
            }
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
     * @param string $paymentHandler
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
        string $paymentHandler,
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
            $this->initClient();
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

            if ($paymentHandler == MobilepayPayment::class) {
                $updateFormParams['payment_methods'] = 'mobilepay';
            } elseif ($paymentHandler == KlarnaPayment::class) {
                $updateFormParams['payment_methods'] = 'klarna-payments';
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

            $responseUpdateLinkContent = json_decode($responseUpdateLink->getBody()->getContents());
            if (property_exists($responseUpdateLinkContent, 'url')) {
                $updateLink = $responseUpdateLinkContent->url;
            }

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
     * @param string $orderId
     * @param $paymentId
     */
    public function updateResponse(string $orderId, $paymentId = null): void
    {
        try {
            $context = Context::createDefaultContext();
            if (! $paymentId) {
                $criteria = new Criteria([$orderId]);
                $criteria->addAssociation('customFields');
                $order = $this->orderRepository->search($criteria, $context)->first();

                $customFields = $order->getCustomFields();
                if ($customFields && isset($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD])) {
                    $data = json_decode($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD]);

                    if (property_exists($data, 'id')) {
                        $paymentId = $data->id;
                    }
                }
            }

            if (! $paymentId) {
                return;
            }

            $this->initClient();
            $response = $this->http->request('GET', 'payments/' . $paymentId);
            if ($response->getStatusCode() === 200) {
                $this->orderRepository->update(
                    [
                        [
                            'id'           => $orderId,
                            'customFields' => [
                                WexoQuickpay::QUICKPAY_RESPONSE_FIELD => $response->getBody()->getContents()
                            ]
                        ]
                    ],
                    $context
                );
            }
        } catch (\Error | \TypeError | \Exception $e) {
            $this->paymentLogger(
                WexoQuickpay::ORDER_CREATE_ERROR,
                [
                    'formParams' => $orderId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'errorType' => get_class($e)
                ]
            );
        }
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
     * @return bool
     */
    public function isConfigValid(array $config): bool
    {
        try {
            $this->initClient();
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

    /**
     * @param string $orderId
     * @param float|null $amount
     * @return bool|null
     */
    public function capturePayment(
        string $orderId,
        ?float $amount = null
    ): ?bool {
        $context = Context::createDefaultContext();
        $context->addExtension('capture', new ArrayStruct([
            'amount' => false
        ]));

        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('transactions');

        /** @var OrderEntity $order */
        $order = $this->orderRepository->search(
            $criteria,
            $context
        )->first();

        // TODO: Send emails to shop admin on payment error
        if (! $order) {
            $this->paymentLogger(
                WexoQuickpay::ORDER_COMPLETE_ERROR,
                [
                    'error' => 'Order with ID ' . $orderId . ' could no be found'
                ]
            );

            return null;
        }

        $transaction = $order->getTransactions()->first();

        $customFields = $order->getCustomFields();
        if (! $customFields || ! isset($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD])) {
            $this->paymentLogger(
                WexoQuickpay::ORDER_COMPLETE_ERROR,
                [
                    'error' => 'QuickPay response could not be found on order',
                    'orderId' => $orderId,
                    'orderNumber' => $order->getOrderNumber() ?? null,
                    'customFields' => $customFields
                ]
            );

            return null;
        }

        /** @var \stdClass $paymentResponse */
        $paymentResponse = json_decode($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD]);
        if (!$paymentResponse
            || !property_exists($paymentResponse, 'id')
            || !property_exists($paymentResponse, 'order_id')
        ) {
            $this->paymentLogger(
                WexoQuickpay::ORDER_COMPLETE_ERROR,
                [
                    'error' => 'QuickPay ID or order ID could not be found in the orders QuickPay response',
                    'orderId' => $orderId,
                    'orderNumber' => $order->getOrderNumber() ?? null,
                    'paymentResponse' => $paymentResponse ?? null
                ]
            );

            return null;
        }

        // Ensure it's the correct order we're trying to capture
        if ($paymentResponse->order_id != $order->getOrderNumber()) {
            return null;
        }

        // TODO: The customer could go into quickpay and withdraw manually.
        $availableAmount = $this->getAvailableAmount($paymentResponse);
        if (! $amount) {
            $amount = $availableAmount;
        } elseif ($amount > $availableAmount) {
            $this->paymentLogger(
                WexoQuickpay::ORDER_COMPLETE_ERROR,
                [
                    'error'           => 'The amount: "' . $amount . '", is not available to capture.',
                    'orderId'         => $orderId,
                    'orderNumber'     => $order->getOrderNumber() ?? null,
                    'paymentResponse' => $paymentResponse ?? null
                ]
            );

            return false;
        }

        $payment = false;
        $orderComplete = true;

        try {
            $this->initClient();
            $response = $this->http->request(
                'POST',
                'payments/' . $paymentResponse->id . '/capture',
                [
                    'form_params' => [
                        'amount' => $amount
                    ]
                ]
            );

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();
            $logEntry = [
                'orderId'            => $orderId,
                'orderNumber'        => $order->getOrderNumber() ?? null,
                'paymentId'          => $paymentResponse->id,
                'responseStatusCode' => $statusCode,
                'response'           => json_decode($responseBody)
            ];

            if ($statusCode === 202) {
                $this->paymentLogger(
                    WexoQuickpay::ORDER_COMPLETE_SUCCESS,
                    $logEntry,
                    Logger::INFO
                );

                $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD] = $responseBody;

                $this->orderRepository->update(
                    [
                        [
                            'id'           => $orderId,
                            'customFields' => $customFields
                        ]
                    ],
                    Context::createDefaultContext()
                );

                $availableAmount = $this->getAvailableAmount(json_decode($responseBody));

                $stateName = $transaction->getStateMachineState()->getTechnicalName();
                if ($availableAmount == 0 && $stateName !== OrderTransactionStates::STATE_PAID) {
                    $this->stateMachineRegistry->transition(
                        new Transition(
                            OrderTransactionDefinition::ENTITY_NAME,
                            $transaction->getId(),
                            StateMachineTransitionActions::ACTION_DO_PAY,
                            'stateId'
                        ),
                        $context
                    );

                    $this->transactionStateHandler->paid(
                        $transaction->getId(),
                        $context
                    );
                } elseif ($stateName !== OrderTransactionStates::STATE_PARTIALLY_PAID) {
                    $this->transactionStateHandler->payPartially(
                        $transaction->getId(),
                        $context
                    );

                    $orderComplete = false;
                } elseif ($stateName === OrderTransactionStates::STATE_PARTIALLY_PAID) {
                    $orderComplete = false;
                }

                $payment = true;
            } else {
                $this->paymentLogger(
                    WexoQuickpay::ORDER_COMPLETE_ERROR,
                    $logEntry
                );

                $quickPayResponse = json_decode($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD]);
                $availableAmount = $this->getAvailableAmount($quickPayResponse);
                if ($availableAmount != 0) {
                    $this->fail($transaction->getId(), $context);

                    $orderComplete = false;
                }
            }
        } catch (\Error | \TypeError | \Exception $e) {
            $this->paymentLogger(
                WexoQuickpay::ORDER_COMPLETE_ERROR,
                [
                    'orderId'   => $orderId ?? null,
                    'error'     => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                    'errorType' => get_class($e)
                ]
            );
        }

        if ($orderComplete) {
            $this->orderService->orderStateTransition(
                $order->getId(),
                StateMachineTransitionActions::ACTION_COMPLETE,
                new ParameterBag(),
                $context
            );
        }

        return $payment;
    }

    /**
     * @param string $transactionId
     * @param Context $context
     */
    public function fail(string $transactionId, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(
                OrderTransactionDefinition::ENTITY_NAME,
                $transactionId,
                StateMachineTransitionActions::ACTION_REOPEN,
                'stateId'
            ),
            $context
        );

        $this->transactionStateHandler->fail(
            $transactionId,
            $context
        );
    }

    /**
     * @param string $transactionId
     * @param Context $context
     */
    public function authorize(string $transactionId, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(
                OrderTransactionDefinition::ENTITY_NAME,
                $transactionId,
                StateMachineTransitionActions::ACTION_AUTHORIZE,
                'stateId'
            ),
            $context
        );
    }

    /**
     * @param \stdClass $quickpayResponse
     * @return float
     */
    protected function getAvailableAmount(\stdClass $quickpayResponse): float
    {
        $capturedAmount = 0;
        $authorizedAmount = 0;
        if (property_exists($quickpayResponse, 'operations')) {
            foreach ($quickpayResponse->operations as $operation) {
                if (! property_exists($operation, 'type') ||
                    ! property_exists($operation, 'amount') ||
                    ! property_exists($operation, 'qp_status_msg') ||
                    $operation->qp_status_msg != 'Approved'
                ) {
                    continue;
                }

                if ($operation->type === 'capture') {
                    $capturedAmount += $operation->amount;
                } elseif ($operation->type === 'authorize') {
                    $authorizedAmount += $operation->amount;
                }
            }
        }

        $availableAmount = $authorizedAmount - $capturedAmount;

        return (float) $availableAmount;
    }
}
