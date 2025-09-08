<?php declare(strict_types=1);

namespace Wexo\Quickpay\Service;

use DateTime;
use DateTimeInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\Transition;
use Wexo\Quickpay\ServiceInterface\QuickpayInterface;
use Wexo\Quickpay\WexoQuickpay;

class SubscriptionQuickpayService extends QuickpayService implements QuickpayInterface
{
    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param SalesChannelContext $salesChannelContext
     * @return void
     * @throws GuzzleException
     */
    public function create(
        AsyncPaymentTransactionStruct &$transaction,
        SalesChannelContext           $salesChannelContext
    ): void {
        $order = $transaction->getOrder();

        $currency = $salesChannelContext->getCurrency()->getIsoCode();

        $autoCaptureAt = (new \DateTime())
            ->modify('+2 days')
            ->setTime(0, 0)
            ->format(DateTime::ATOM);
        $salesChannelName = $salesChannelContext->getSalesChannel()->getName();

        // We're adding a -S to the orderId for the subscription, as the recurring payment will use the orderId.
        $formParams = [
            'currency' => $currency,
            'order_id' => $order->getOrderNumber(),
            'description' => $salesChannelName,
            'auto_capture_at' => $autoCaptureAt
        ];

        $subscriptionResponse = $this->getClient($salesChannelContext->getSalesChannelId())
            ->request('POST', 'subscriptions', [
                'json' => $formParams
            ]);

        if ($subscriptionResponse->getStatusCode() !== 201) {
            throw new Exception(
                $subscriptionResponse->getBody()->getContents()
                ?? 'Failed to create payment for order '
                . $formParams['order_id'] ?? null
            );
        }

        $content = $subscriptionResponse->getBody()->getContents();

        $responseData = json_decode($content, true);
        $customFields = [
            WexoQuickpay::QUICKPAY_SUBSCRIPTION_ID => (string)$responseData['id'],
            WexoQuickpay::QUICKPAY_RESPONSE_FIELD => $content
        ];

        $this->setOrderCustomFields($order->getId(), $customFields);
        $transaction->getOrder()->setCustomFields($customFields);
    }

    /**
     * @param string $subscriptionId
     * @param SalesChannelContext $salesChannelContext
     * @return void
     * @throws GuzzleException
     * @throws \Exception
     */
    public function cancel(
        string $subscriptionId,
        SalesChannelContext $salesChannelContext
    ): void {
        $response = $this->getClient($salesChannelContext->getSalesChannelId())->request(
            'POST',
            'subscriptions/' . $subscriptionId . '/cancel'
        );

        if ($response->getStatusCode() !== 202) {
            throw new \Exception(
                'Failed to cancel subscription with ID ' . $subscriptionId
                . '. Response: ' . $response->getBody()->getContents()
            );
        }

        $content = $response->getBody()->getContents();
        $responseData = json_decode($content, true);

        $customFields = [
            WexoQuickpay::QUICKPAY_SUBSCRIPTION_ID => (string)$responseData['id'],
            WexoQuickpay::QUICKPAY_RESPONSE_FIELD => $content
        ];
    }

    /**
     * @param string $orderId
     * @param SalesChannelContext $salesChannelContext
     * @return array
     * @throws Exception
     */
    public function renew(
        string $orderId,
        string $newOrderNumber,
        SalesChannelContext $salesChannelContext
    ): OrderEntity {
        $context = $salesChannelContext->getContext();
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociations(['lineItems', 'deliveries', 'transactions']);

        /** @var OrderEntity $originalOrder */
        $originalOrder = $this->orderRepository->search($criteria, $context)->first();

        if (!$originalOrder) {
            throw new \Exception('Order not found');
        }

        $lineItems = array_map(function ($item) {
            $price = $item->getPrice();

            if (!$price) {
                throw new \Exception('Price information is missing for an item');
            }

            return [
                'identifier' => $item->getIdentifier(),
                'productId' => $item->getProductId(),
                'referencedId' => $item->getReferencedId(),
                'label' => $item->getLabel(),
                'quantity' => $item->getQuantity(),
                'unitPrice' => $price->getUnitPrice(),
                'totalPrice' => $price->getTotalPrice(),
                'price' => $price,
                'type' => $item->getType(),
                'payload' => $item->getPayload(),
            ];
        }, $originalOrder->getLineItems()->getElements());

        $deliveries = array_map(function ($delivery) {
            $shippingCosts = $delivery->getShippingCosts();
            return [
                'shippingCosts' => [
                    'unitPrice' => $shippingCosts->getUnitPrice(),
                    'quantity' => $shippingCosts->getQuantity(),
                    'totalPrice' => $shippingCosts->getTotalPrice(),
                    'calculatedTaxes' => $shippingCosts->getCalculatedTaxes()->jsonSerialize(),
                    'taxRules' => $shippingCosts->getTaxRules()->jsonSerialize(),
                    'referencePrice' => $shippingCosts->getReferencePrice()?->jsonSerialize(),
                    'listPrice' => $shippingCosts->getListPrice()?->jsonSerialize(),
                    'regulationPrice' => $shippingCosts->getRegulationPrice()?->jsonSerialize(),
                ],
                'shippingOrderAddress' => [
                    'firstName' => $delivery->getShippingOrderAddress()->getFirstName(),
                    'lastName' => $delivery->getShippingOrderAddress()->getLastName(),
                    'street' => $delivery->getShippingOrderAddress()->getStreet(),
                    'zipcode' => $delivery->getShippingOrderAddress()->getZipcode(),
                    'city' => $delivery->getShippingOrderAddress()->getCity(),
                    'countryId' => $delivery->getShippingOrderAddress()->getCountryId(),
                ],
                'shippingMethodId' => $delivery->getShippingMethodId(),
                'shippingDateEarliest' => $delivery->getShippingDateEarliest(),
                'shippingDateLatest' => $delivery->getShippingDateLatest(),
                'stateId' => $delivery->getStateId(),
            ];
        }, $originalOrder->getDeliveries()->getElements());

        $transactions = array_map(function ($transaction) {
            return [
                'amount' => $transaction->getAmount()->jsonSerialize(),
                'paymentMethodId' => $transaction->getPaymentMethodId(),
                'stateId' => $transaction->getStateId(),
            ];
        }, $originalOrder->getTransactions()->getElements());

        $newOrderData = [
            'salesChannelId' => $originalOrder->getSalesChannelId(),
            'orderNumber' => $newOrderNumber,
            'billingAddressId' => $originalOrder->getBillingAddressId(),
            'currencyId' => $originalOrder->getCurrencyId(),
            'price' => $originalOrder->getPrice(),
            'shippingCosts' => $originalOrder->getShippingCosts(),
            'orderDateTime' => new \DateTime(),
            'orderCustomer' => [
                'customerId' => $originalOrder->getOrderCustomer()->getCustomerId(),
                'email' => $originalOrder->getOrderCustomer()->getEmail(),
                'firstName' => $originalOrder->getOrderCustomer()->getFirstName(),
                'lastName' => $originalOrder->getOrderCustomer()->getLastName(),
                'salutationId' => $originalOrder->getOrderCustomer()->getSalutationId(),
            ],
            'deepLinkCode' => bin2hex(random_bytes(16)),
            'ruleIds' => $originalOrder->getRuleIds(),
            'currencyFactor' => $originalOrder->getCurrencyFactor() ?? 1.0,
            'itemRounding' => $originalOrder->getItemRounding()->jsonSerialize(),
            'totalRounding' => $originalOrder->getTotalRounding()->jsonSerialize(),
            'lineItems' => $lineItems,
            'deliveries' => $deliveries,
            'transactions' => $transactions,
            'stateId' => $originalOrder->getStateId(),
            'customFields' => $originalOrder->getCustomFields() ?? [],
        ];

        $this->orderRepository->upsert([$newOrderData], $context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salesChannelId', $originalOrder->getSalesChannelId()));
        $criteria->addFilter(new EqualsFilter('orderNumber', $newOrderNumber));
        $criteria->addAssociations(['lineItems', 'deliveries', 'transactions']);
        $newOrder = $this->orderRepository->search($criteria, $context)->first();

        if (!$newOrder) {
            throw new \Exception('Failed to create a new order');
        }

        $newOrder->addExtension('quantities', new ArrayEntity(['quantities' => array_column($lineItems, 'quantity')]));

        return $newOrder;
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param SalesChannelContext $salesChannelContext
     * @return string
     * @throws GuzzleException
     */
    public function getLink(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext           $salesChannelContext,
        array                         $extraParams = []
    ): string {
        $returnUrl = $transaction->getReturnUrl();

        $callbackUrl = str_replace('finalize-transaction', 'quickpay-finalize-transaction', (string)$returnUrl);

        $order = $transaction->getOrder();

        $customFields = $order->getCustomFields();
        $subscriptionResponse = \json_decode((string)$customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD], true);

        $updateFormParams = [
            'amount' => $transaction->getOrder()->getAmountTotal() * 100,
            'continue_url' => $callbackUrl . '&status=accepted',
            'cancel_url' => $callbackUrl . '&status=cancel',
            'callback_url' => $callbackUrl,
            'language' => $this->getLanguage(
                $salesChannelContext->getSalesChannel()->getLanguageId(),
                $salesChannelContext->getContext()
            )
        ];

        if (!empty($extraParams)) {
            $updateFormParams = array_merge($updateFormParams, $extraParams);
        }

        $linkResponse = $this->getClient($salesChannelContext->getSalesChannelId())
            ->request('put', 'subscriptions/' . $subscriptionResponse['id'] . "/link", [
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
                'subscriptionResponse' => $subscriptionResponse,
                'linkResponse' => $linkResponseContent
            ],
            Logger::INFO
        );

        return $linkResponseContent['url'];
    }

    /**
     * @throws GuzzleException
     */
    public function recurring(
        string $orderId,
        ?bool $initial = false
    ): void {
        $context = Context::createDefaultContext();

        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('deliveries');
        $criteria->addAssociation('salesChannel.domains');

        /** @var OrderEntity $order */
        $order = $this->orderRepository->search(
            $criteria,
            $context
        )->first();

        // TODO: Send emails to shop admin on payment error
        if (!$order) {
            $this->paymentLogger(
                WexoQuickpay::ORDER_COMPLETE_ERROR,
                [
                    'error' => 'Order with ID ' . $orderId . ' could no be found'
                ]
            );

            return;
        }

        $states = [
            OrderTransactionStates::STATE_OPEN,
            OrderTransactionStates::STATE_AUTHORIZED
        ];

        foreach ($states as $state) {
            $transaction = $order->getTransactions()->filterByState($state)->first();
            if ($transaction) {
                break;
            }
        }

        if (!$transaction) {
            return;
        }

        $customFields = $order->getCustomFields();
        if (!$customFields || !isset($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD])) {
            $this->paymentLogger(
                WexoQuickpay::ORDER_COMPLETE_ERROR,
                [
                    'error' => 'QuickPay response could not be found on order',
                    'orderId' => $orderId,
                    'orderNumber' => $order->getOrderNumber() ?? null,
                    'customFields' => $customFields
                ]
            );

            return;
        }

        $quickPayResponse = json_decode((string)$customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD], true);
        $subscriptionId = $customFields[WexoQuickpay::QUICKPAY_SUBSCRIPTION_ID];
        if (!$subscriptionId) {
            $this->paymentLogger(
                WexoQuickpay::ORDER_COMPLETE_ERROR,
                [
                    'error' => 'Subscription ID is not set on the order',
                    'orderId' => $orderId,
                    'orderNumber' => $order->getOrderNumber() ?? null,
                    'paymentResponse' => $quickPayResponse ?? null
                ]
            );

            return;
        }

        $autoCaptureAt = (new \DateTime())
            ->modify('+2 days')
            ->setTime(0, 0)
            ->format(DateTime::ATOM);

        // We're adding a -S to the orderId for the subscription, as the recurring payment will use the orderId.

        $recurringOrderNumber = $order->getOrderNumber() . '-S';

        $data = [
            'amount' => $order->getAmountTotal() * 100,
            'order_id' => $recurringOrderNumber,
            'auto_capture_at' => $autoCaptureAt
        ];

        // A custom callback made for recurring payments, so we can validate them.
        $domain = $order->getSalesChannel()->getDomains() ? $order->getSalesChannel()->getDomains()->first() : null;
        if ($domain) {
            $baseUrl = rtrim((string)$domain->getUrl(), '/');
            $data['QuickPay-Callback-Url'] = $baseUrl . '/api/wexo/quickpay/recurring-callback';
        }

        try {
            $response = $this->getClient($order->getSalesChannelId())->request(
                'POST',
                'subscriptions/' . $subscriptionId . '/recurring',
                [
                    'form_params' => $data
                ]
            );
        } catch (\Exception $e) {
            $this->paymentLogger(
                WexoQuickpay::ORDER_COMPLETE_ERROR,
                [
                    'orderId' => $orderId ?? null,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'errorType' => $e::class
                ]
            );
            return;
        }

        $statusCode = $response->getStatusCode();
        $logEntry = [
            'orderId' => $orderId,
            'orderNumber' => $order->getOrderNumber() ?? null,
            'subscriptionId' => $subscriptionId,
            'responseStatusCode' => $statusCode,
            'response' => json_decode($response->getBody()->getContents())
        ];

        $paymentState = $transaction->getStateMachineState()->getTechnicalName();
        $orderState = $order->getStateMachineState()->getTechnicalName();
        $responseBody = $response->getBody()->getContents();
        if ($statusCode === 202 || $statusCode === 200) {
            if (!$responseBody) {
                $location = explode("/", $response->getHeader('Location')[0]);
                $quickpayPaymentId = $location[4];

                try {
                    $response = $this->getClient($order->getSalesChannelId())->request(
                        'GET',
                        'payments/' . $quickpayPaymentId
                    );
                } catch (\Exception $e) {
                    $this->paymentLogger(
                        WexoQuickpay::ORDER_COMPLETE_ERROR,
                        [
                            'orderId' => $orderId ?? null,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                            'errorType' => $e::class
                        ]
                    );
                }

                $responseBody = $response->getBody()->getContents();
            }
            $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD] = $responseBody;
            $this->setOrderCustomFields($order->getId(), $customFields);

            $responseBodyData = json_decode($responseBody, true);
            if ($responseBodyData['state'] === 'processed') {
                $this->transactionStateHandler->authorize(
                    $transaction->getId(),
                    $context
                );
            }

            if ($orderState === OrderStates::STATE_OPEN) {
                $this->stateMachineRegistry->transition(
                    new Transition(
                        OrderDefinition::ENTITY_NAME,
                        $orderId,
                        StateMachineTransitionActions::ACTION_PROCESS,
                        'stateId'
                    ),
                    $context
                );
            }
        } else {
            if ($paymentState !== OrderTransactionStates::STATE_OPEN) {
                $this->transactionStateHandler->reopen(
                    $transaction->getId(),
                    $context
                );
            }

            $this->transactionStateHandler->fail(
                $transaction->getId(),
                $context
            );

            $this->paymentLogger(
                WexoQuickpay::ORDER_COMPLETE_ERROR,
                $logEntry
            );
        }
    }

    public function checkSubscriptionStatusByOrderId(
        string $orderId,
        SalesChannelContext $salesChannelContext
    ) {
        $subscriptionResponse = $this->getClient($salesChannelContext->getSalesChannelId())
            ->request('GET', 'subscriptions', [
                'query' => [
                    'order_id' => $orderId
                ]
            ]);

        $content = $subscriptionResponse->getBody()->getContents();
        $responseData = json_decode($content, true);

        return $responseData;
    }

    /**
     * @throws GuzzleException
     */
    // Taken from PaymentQucikpayService
    // only changed different comparing method for subscriptionCapture
    public function subscriptionCapture(
        string $orderId,
        ?float $amount = null
    ): ?bool {
        $context = Context::createDefaultContext();
        $context->addExtension('capture', new ArrayStruct([
            'amount' => false
        ]));

        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('deliveries');

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

        $states = [
            OrderTransactionStates::STATE_PAID,
            OrderTransactionStates::STATE_PARTIALLY_PAID,
            OrderTransactionStates::STATE_AUTHORIZED
        ];

        foreach ($states as $state) {
            $transaction = $order->getTransactions()->filterByState($state)->first();
            if ($transaction) {
                break;
            }
        }

        if (!$transaction) {
            return false;
        }

        $paymentResponse = $this->updateResponse($orderId);
        $paymentResponse = $paymentResponse ? json_decode($paymentResponse) : null;
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

        // Ensure it's the correct QuickPay payment by comparing paymentid
        $customFields = $order->getCustomFields();
        if ($customFields && isset($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD])) {
            $data = json_decode((string) $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD]);

            if (is_object($data) && property_exists($data, 'id')) {
                if ((int) $paymentResponse->id !== (int) $data->id) {
                    $this->paymentLogger(
                        WexoQuickpay::ORDER_COMPLETE_ERROR,
                        [
                            'error'            => 'QuickPay payment id mismatch',
                            'orderId'          => $orderId,
                            'orderNumber'      => $order->getOrderNumber() ?? null,
                            'expectedPaymentId'=> (int) $data->id,
                            'actualPaymentId'  => (int) $paymentResponse->id,
                        ]
                    );
                    return null;
                }
            }
        }

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
                    'paymentResponse' => $paymentResponse
                ]
            );

            return false;
        }

        $payment = false;
        $orderComplete = true;

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

            if (! $responseBody) {
                $responseBody = $this->updateResponse($orderId, $paymentResponse->id);
            } else {
                $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD] = $responseBody;
                $this->setOrderCustomFields($orderId, $customFields);
            }

            $availableAmount = $this->getAvailableAmount(json_decode($responseBody)) - (float) $amount;
            $stateName = $transaction->getStateMachineState()->getTechnicalName();
            if ($availableAmount == 0.0 && $stateName !== OrderTransactionStates::STATE_PAID) {
                if ($stateName !== OrderTransactionStates::STATE_AUTHORIZED) {
                    $this->transactionStateHandler->process(
                        $transaction->getId(),
                        $context
                    );
                }

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
            } elseif ($stateName === OrderTransactionStates::STATE_PARTIALLY_PAID) {
                $orderComplete = false;
            }

            $payment = true;
        } else {
            $this->paymentLogger(
                WexoQuickpay::ORDER_COMPLETE_ERROR,
                $logEntry
            );

            $quickPayResponse = json_decode((string) $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD]);
            $availableAmount = $this->getAvailableAmount($quickPayResponse);
            if ($availableAmount != 0) {
                $this->transactionStateHandler->reopen(
                    $transaction->getId(),
                    $context
                );

                $this->transactionStateHandler->fail(
                    $transaction->getId(),
                    $context
                );

                $orderComplete = false;
            }
        }

        if ($orderComplete) {
            $this->stateMachineRegistry->transition(
                new Transition(
                    OrderDefinition::ENTITY_NAME,
                    $order->getId(),
                    StateMachineTransitionActions::ACTION_COMPLETE,
                    'stateId'
                ),
                $context
            );

            $updateShipping = $this->systemConfigService->get('WexoQuickpay.config.quickpayUpdateShipping');
            $delivery = $order->getDeliveries()->first();
            if ($updateShipping &&
                $delivery &&
                $delivery->getStateMachineState()->getTechnicalName() !== OrderDeliveryStates::STATE_SHIPPED
            ) {
                $this->stateMachineRegistry->transition(
                    new Transition(
                        OrderDeliveryDefinition::ENTITY_NAME,
                        $delivery->getId(),
                        StateMachineTransitionActions::ACTION_SHIP,
                        'stateId'
                    ),
                    $context
                );
            }
        }

        return $payment;
    }
    private function getAvailableAmount(\stdClass $quickpayResponse): float
    {
        $capturedAmount = 0;
        $authorizedAmount = 0;
        if (property_exists($quickpayResponse, 'operations')) {
            foreach ($quickpayResponse->operations as $operation) {
                $approved = false;
                if ((property_exists($operation, 'qp_status_msg') &&
                        $operation->qp_status_msg == 'Approved') ||
                    (property_exists($operation, 'aq_status_msg') &&
                        $operation->aq_status_msg == 'Approved')
                ) {
                    $approved = true;
                }

                if (! property_exists($operation, 'type') ||
                    ! property_exists($operation, 'amount') ||
                    ! $approved
                ) {
                    continue;
                }

                if ($operation->type === 'capture') {
                    $capturedAmount += $operation->amount;
                } elseif ($operation->type === 'authorize') {
                    $authorizedAmount += $operation->amount;
                } elseif ($operation->type === 'recurring') {
                    $authorizedAmount += $operation->amount;
                }
            }
        }

        $availableAmount = $authorizedAmount - $capturedAmount;

        return (float) $availableAmount;
    }

}
