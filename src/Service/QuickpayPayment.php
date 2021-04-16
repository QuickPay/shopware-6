<?php declare(strict_types=1);

namespace Wexo\Quickpay\Service;

use Exception;
use GuzzleHttp\Client;
use Monolog\Logger;
use Shopware\Core\Checkout\Cart\CartPersisterInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
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
     * @var EntityRepositoryInterface $languageRepository
     */
    protected $languageRepository;
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
     * @var Client[]
     */
    protected $apiClients = [];
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
     * @param EntityRepositoryInterface $languageRepository
     * @param OrderTransactionStateHandler $transactionStateHandler
     * @param OrderService $orderService
     * @param CartPersisterInterface $cartPersister
     * @param StateMachineRegistry $stateMachineRegistry
     */
    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepositoryInterface $logEntryRepository,
        EntityRepositoryInterface $orderRepository,
        EntityRepositoryInterface $languageRepository,
        OrderTransactionStateHandler $transactionStateHandler,
        OrderService $orderService,
        CartPersisterInterface $cartPersister,
        StateMachineRegistry $stateMachineRegistry
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->logEntryRepository = $logEntryRepository;
        $this->orderRepository = $orderRepository;
        $this->languageRepository = $languageRepository;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->orderService = $orderService;
        $this->cartPersister = $cartPersister;
        $this->stateMachineRegistry = $stateMachineRegistry;
    }

    /**
     * @param string|null $salesChannelId
     * @return Client
     */
    public function getClient(?string $salesChannelId): Client
    {
        //If no string is supplied to system config service, it uses 'global' under the hood.
        if (!$salesChannelId) {
            $salesChannelId = 'global';
        }
        if (!isset($this->apiClients[$salesChannelId])) {
            $apiKey = $this->systemConfigService->get('WexoQuickpay.config.quickpayApiKey', $salesChannelId);

            $this->apiClients[$salesChannelId] = new Client([
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
        return $this->apiClients[$salesChannelId];
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
            $customFields = $transaction->getOrder()->getCustomFields();
            // Only create a payment if one does not already exist.
            if (! $customFields || ($customFields && ! isset($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD]))) {
                $this->addPaymentToOrder($transaction, $salesChannelContext);
            }
            $link = $this->getPaymentLink($transaction, $salesChannelContext);
        } catch (Exception $e) {
            $this->paymentLogger(
                WexoQuickpay::ORDER_CREATE_ERROR,
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'errorType' => get_class($e)
                ]
            );
            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                'An error occurred during the communication with external payment gateway' . PHP_EOL .
                $e->getMessage()
            );
        }
        // Redirect to external gateway
        return new RedirectResponse($link);
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @throws Exception
     */
    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        $context = $salesChannelContext->getContext();

        $transactionId = $transaction->getOrderTransaction()->getId();

        $paymentState = $transaction->getOrderTransaction()->getStateMachineState()->getTechnicalName();
        $orderState = $transaction->getOrder()->getStateMachineState()->getTechnicalName();

        $content = $request->getContent();

        $status = $request->get('status');
        if ($status == "accepted") {
            $this->cartPersister->delete($salesChannelContext->getToken(), $salesChannelContext);
        } elseif ($status == "cancel") {
            if ($orderState !== OrderStates::STATE_CANCELLED) {
                $this->orderService->orderStateTransition(
                    $transaction->getOrder()->getId(),
                    StateMachineTransitionActions::ACTION_CANCEL,
                    new ParameterBag(),
                    $context
                );
            }

            throw new CustomerCanceledAsyncPaymentException(
                $transactionId,
                'Customer canceled the payment on the payment page'
            );
        } elseif ($content) {
            $response = json_decode($content, true);
            $this->paymentLogger(
                'quickpay_callback_data_response',
                [
                    'orderId' => $transaction->getOrder()->getId(),
                    'data' => $response
                ]
            );

            $key = $this->systemConfigService->get('WexoQuickpay.config.quickpayPrivateKey');

            $checksum = hash_hmac('sha256', $content, $key);
            $submittedChecksum = $request->server->get('HTTP_QUICKPAY_CHECKSUM_SHA256') ?? null;
            if ($submittedChecksum && $checksum !== $submittedChecksum) {
                throw new \Exception('Checksum check failed');
            }



            if (isset($response['accepted']) && $response['accepted']) {
                $this->paymentSuccess($transaction, $context, $paymentState, $orderState);
            } elseif (isset($response['accepted']) && ! $response['accepted']) {
                if ($paymentState !== OrderTransactionStates::STATE_CANCELLED) {
                    $this->stateMachineRegistry->transition(
                        new Transition(
                            OrderTransactionDefinition::ENTITY_NAME,
                            $transactionId,
                            StateMachineTransitionActions::ACTION_CANCEL,
                            'stateId'
                        ),
                        $context
                    );
                }

                if ($orderState !== OrderStates::STATE_CANCELLED) {
                    $this->orderService->orderStateTransition(
                        $transaction->getOrder()->getId(),
                        StateMachineTransitionActions::ACTION_CANCEL,
                        new ParameterBag(),
                        $context
                    );
                }
            }

            $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD] = $response;

            $this->orderRepository->update(
                [
                    [
                        'id'           => $transaction->getOrder()->getId(),
                        'customFields' => $customFields
                    ]
                ],
                Context::createDefaultContext()
            );
        }
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param Context $context
     * @param string $paymentState
     * @param string $orderState
     */
    protected function paymentSuccess(
        AsyncPaymentTransactionStruct $transaction,
        Context $context,
        string $paymentState,
        string $orderState
    ): void {
        if ($paymentState !== 'authorized') {
            if ($paymentState === OrderTransactionStates::STATE_CANCELLED) {
                $this->stateMachineRegistry->transition(
                    new Transition(
                        OrderTransactionDefinition::ENTITY_NAME,
                        $transaction->getOrderTransaction()->getId(),
                        StateMachineTransitionActions::ACTION_REOPEN,
                        'stateId'
                    ),
                    $context
                );
            }

            $this->stateMachineRegistry->transition(
                new Transition(
                    OrderTransactionDefinition::ENTITY_NAME,
                    $transaction->getOrderTransaction()->getId(),
                    StateMachineTransitionActions::ACTION_AUTHORIZE,
                    'stateId'
                ),
                $context
            );
        }

        if ($orderState !== OrderStates::STATE_IN_PROGRESS) {
            if ($orderState === OrderStates::STATE_CANCELLED) {
                $this->orderService->orderStateTransition(
                    $transaction->getOrder()->getId(),
                    StateMachineTransitionActions::ACTION_REOPEN,
                    new ParameterBag(),
                    $context
                );
            }

            $this->orderService->orderStateTransition(
                $transaction->getOrder()->getId(),
                StateMachineTransitionActions::ACTION_PROCESS,
                new ParameterBag(),
                $context
            );
        }
    }
    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param SalesChannelContext $salesChannelContext
     * @throws Exception
     */
    public function addPaymentToOrder(
        AsyncPaymentTransactionStruct &$transaction,
        SalesChannelContext $salesChannelContext
    ): void {
        $order = $transaction->getOrder();
        $basket = [];
        foreach ($order->getLineItems() as $orderLineItem) {
            $payload = $orderLineItem->getPayload();
            $itemNo = ($payload && isset($payload['productNumber']))
                ? $payload['productNumber']
                : $orderLineItem->getLabel();
            $basket[] = [
                'qty' => $orderLineItem->getQuantity(),
                'item_no' => $itemNo,
                'item_name' => $orderLineItem->getLabel(),
                'item_price' => $orderLineItem->getUnitPrice() * 100,
                'vat_rate' => $orderLineItem->getPrice()->getTaxRules()->first()->getTaxRate() / 100,
            ];
        }

        $shippingTotal = $order->getShippingTotal();
        $shippingTaxRate = $order->getShippingCosts()->getTaxRules()->first()->getTaxRate();
        if ($shippingTotal && $shippingTaxRate) {
            $basket[] = [
                'qty' => 1,
                'item_no' => 'Shipping',
                'item_name' => 'Shipping',
                'item_price' => $shippingTotal * 100,
                'vat_rate' => $shippingTaxRate / 100,
            ];
        }

        $currency = $salesChannelContext->getCurrency()->getIsoCode()
            ?? WexoQuickpay::FALLBACK_CURRENCY;

        $formParams = [
            'currency' => $currency,
            'order_id' => $order->getOrderNumber(),
            'basket' => $basket
        ];

        $paymentResponse = $this->getClient($salesChannelContext->getSalesChannelId())
            ->request('POST', 'payments', [
                'json' => $formParams
            ]);

        if ($paymentResponse->getStatusCode() !== 201) {
            throw new Exception(
                $paymentResponse->getBody()->getContents()
                ?? 'Failed to create payment for order '
                . $formParams['order_id'] ?? null
            );
        }

        $content = $paymentResponse->getBody()->getContents();

        $customFields = [
            WexoQuickpay::QUICKPAY_RESPONSE_FIELD => $content
        ];

        $this->orderRepository->update(
            [
                [
                    'id' => $order->getId(),
                    'customFields' => $customFields
                ]
            ],
            $salesChannelContext->getContext()
        );

        $transaction->getOrder()->setCustomFields($customFields);
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param SalesChannelContext $salesChannelContext
     * @return string
     * @throws Exception
     */
    public function getPaymentLink(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext
    ): string {
        $returnUrl = $transaction->getReturnUrl();

        $callbackUrl = str_replace('finalize-transaction', 'quickpay-finalize-transaction', $returnUrl);

        $updateFormParams = [
            'amount' => $transaction->getOrder()->getAmountTotal() * 100,
            'continue_url' => $returnUrl . '&status=accepted',
            'cancel_url' => $returnUrl . '&status=cancel',
            'callback_url' => $callbackUrl,
            'language' => $this->getLanguage(
                $salesChannelContext->getSalesChannel()->getLanguageId(),
                $salesChannelContext->getContext()
            )
        ];

        $order = $transaction->getOrder();
        $paymentHandler = $transaction->getOrderTransaction()->getPaymentMethod()->getHandlerIdentifier();
        if ($paymentHandler === MobilepayPayment::class) {
            $updateFormParams['payment_methods'] = 'mobilepay';
        } elseif ($paymentHandler === KlarnaPayment::class) {
            $updateFormParams['payment_methods'] = 'klarna-payments';
        } elseif ($paymentHandler == ViabillPayment::class) {
            $updateFormParams['payment_methods'] = 'viabill';
        }

        $customFields = $order->getCustomFields();
        $paymentResponse = \json_decode($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD], true);
        $linkResponse = $this->getClient($salesChannelContext->getSalesChannelId())
            ->request('put', 'payments/' . $paymentResponse['id'] . "/link", [
                'form_params' => $updateFormParams
            ]);

        if ($linkResponse->getStatusCode() !== 200) {
            throw new Exception(
                $linkResponse->getBody()->getContents()
                ?? 'Failed to link payment for order '
                . $order->getOrderNumber()
            );
        }

        $linkResponseContent = json_decode($linkResponse->getBody()->getContents(), true);

        if (!isset($linkResponseContent['url'])) {
            throw new Exception(
                $linkResponse->getBody()->getContents()
                ?? 'Failed to link payment for order '
                . $order->getOrderNumber()
            );
        }

        $this->paymentLogger(
            WexoQuickpay::ORDER_CREATE_SUCCESS,
            [
                'orderId' => $order->getOrderNumber(),
                'updateFormParams' => $updateFormParams,
                'paymentResponse' => $paymentResponse,
                'linkResponse' => $linkResponseContent
            ],
            Logger::INFO
        );

        return $linkResponseContent['url'];
    }

    /**
     * @param string $orderId
     * @param null $paymentId
     * @param SalesChannelContext|null $context
     * @return array
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    public function updateResponse(
        string $orderId,
        $paymentId = null,
        ?SalesChannelContext $context = null
    ): array {
        try {
            $context = $context ? $context->getContext() : Context::createDefaultContext();

            /** @var OrderEntity $order */
            $order = $this->orderRepository->search(new Criteria([$orderId]), $context)->first();
            if (! $order) {
                return [];
            }

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
                return [];
            }

            $response = $this->getClient($order->getSalesChannelId())
                ->request('GET', 'payments/' . $paymentId);
            $content = $response->getBody()->getContents();
            if ($response->getStatusCode() === 200 && $content) {
                $this->orderRepository->update(
                    [
                        [
                            'id'           => $orderId,
                            'customFields' => [
                                WexoQuickpay::QUICKPAY_RESPONSE_FIELD => $content
                            ]
                        ]
                    ],
                    $context
                );

                return json_decode($content, true);
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

        return [];
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
            $response = $this->getClient(null)->request('GET', 'payments', [
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

        $transaction = $order->getTransactions()->filterByState(OrderTransactionStates::STATE_PAID)->first();
        if (! $transaction) {
            $transaction = $order->getTransactions()
                ->filterByState(OrderTransactionStates::STATE_PARTIALLY_PAID)->first();
        }

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
            $response = $this->getClient($order->getSalesChannelId())->request(
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
                'response'           => json_decode($response->getBody()->getContents())
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

                $availableAmount = $this->getAvailableAmount(json_decode($responseBody)) - (float) $amount;
                $stateName = $transaction->getStateMachineState()->getTechnicalName();
                if ($availableAmount == 0.0 && $stateName !== OrderTransactionStates::STATE_PAID) {
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
                } elseif ($stateName !== OrderTransactionStates::STATE_PAID &&
                    $stateName !== OrderTransactionStates::STATE_PARTIALLY_PAID) {
                    $this->transactionStateHandler->payPartially(
                        $transaction->getId(),
                        $context
                    );

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

            return $payment;
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

    /**
     * @param string $languageId
     * @param Context $context
     * @return string
     */
    private function getLanguage(string $languageId, Context $context): string
    {
        $criteria = new Criteria([$languageId]);
        $criteria->addAssociation('locale');
        $language = $this->languageRepository->search($criteria, $context)->first();

        // Map both norwegian locales to no
        $map = [
            'nb' => 'no',
            'nn' => 'no',
        ];

        $language = explode('-', $language->getLocale()->getCode())[0];

        if (isset($map[$language])) {
            return $map[$language];
        }

        return $language;
    }
}
