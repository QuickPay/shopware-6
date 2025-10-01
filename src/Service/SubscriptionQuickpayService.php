<?php declare(strict_types=1);

namespace Wexo\Quickpay\Service;

use DateTimeInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Random\RandomException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\Transition;
use Wexo\Quickpay\ServiceInterface\QuickpayInterface;
use Wexo\Quickpay\WexoQuickpay;

class SubscriptionQuickpayService extends QuickpayService implements QuickpayInterface
{
    /**
     * @param PaymentTransactionStruct $transaction
     * @param Context $context
     * @return void
     * @throws GuzzleException
     * @throws Exception
     */
    public function create(
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        $tx = $this->loadTransaction($transaction->getOrderTransactionId(), $context);
        $order = $tx->getOrder();

        $currency = $order?->getCurrency()?->getIsoCode() ?? '';

        if ($currency === '') {
            throw new \RuntimeException(sprintf(
                'Currency missing for order %s',
                $order?->getId() ?? '(unknown)'
            ));
        }

        $autoCaptureAt = (new \DateTime())
            ->modify('+2 days')
            ->setTime(0, 0)
            ->format(DateTimeInterface::ATOM);
        $salesChannelName = $order?->getSalesChannel()?->getName();

        if ($salesChannelName === '') {
            throw new \RuntimeException(sprintf(
                'Sales channel missing for order %s',
                $order?->getId() ?? '(unknown)'
            ));
        }

        if ($order === null) {
            throw new \RuntimeException(
                sprintf('Order not found for transaction %s', $transaction->getOrderTransactionId())
            );
        }

        // We're adding a -S to the orderId for the subscription, as the recurring payment will use the orderId.
        $formParams = [
            'currency' => $currency,
            'order_id' => $order->getOrderNumber() . '-S',
            'description' => $salesChannelName,
            'auto_capture_at' => $autoCaptureAt
        ];

        $subscriptionResponse = $this->getClient($order->getSalesChannelId())
            ->request('POST', 'subscriptions', [
                'json' => $formParams
            ]);

        if ($subscriptionResponse->getStatusCode() !== 201) {
            throw new Exception(
                $subscriptionResponse->getBody()->getContents()
                . $formParams['order_id']
            );
        }

        $content = $subscriptionResponse->getBody()->getContents();

        $responseData = json_decode($content, true);
        $customFields = [
            WexoQuickpay::QUICKPAY_SUBSCRIPTION_ID => (string)$responseData['id'],
            WexoQuickpay::QUICKPAY_RESPONSE_FIELD => $content
        ];

        $this->setOrderCustomFields($order->getId(), $customFields, $context);
        $order->setCustomFields($customFields);
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
     * @param string $newOrderNumber
     * @param SalesChannelContext $salesChannelContext
     * @return OrderEntity
     * @throws RandomException
     * @throws Exception
     */
    public function renew(
        string $orderId,
        string $newOrderNumber,
        SalesChannelContext $salesChannelContext
    ): OrderEntity {
        $context = $salesChannelContext->getContext();
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociations(['lineItems', 'deliveries.shippingOrderAddress', 'transactions']);

        /** @var OrderEntity|null $originalOrder */
        $originalOrder = $this->orderRepository->search($criteria, $context)->first();

        if ($originalOrder === null) {
            throw new \Exception('Order not found');
        }

        $lineItems = array_map(function ($item) {
            $price = $item->getPrice();

            if ($price === null) {
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
        }, $originalOrder->getLineItems()?->getElements() ?? []);

        $deliveries = array_map(function ($delivery) {
            $shippingCosts = $delivery->getShippingCosts();
            $shippingOrderAddress = $delivery->getShippingOrderAddress();
            
            if ($shippingOrderAddress === null) {
                throw new \RuntimeException('Shipping order address is required for delivery');
            }
            
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
                    'firstName' => $shippingOrderAddress->getFirstName(),
                    'lastName' => $shippingOrderAddress->getLastName(),
                    'street' => $shippingOrderAddress->getStreet(),
                    'zipcode' => $shippingOrderAddress->getZipcode(),
                    'city' => $shippingOrderAddress->getCity(),
                    'countryId' => $shippingOrderAddress->getCountryId(),
                ],
                'shippingMethodId' => $delivery->getShippingMethodId(),
                'shippingDateEarliest' => $delivery->getShippingDateEarliest(),
                'shippingDateLatest' => $delivery->getShippingDateLatest(),
                'stateId' => $delivery->getStateId(),
            ];
        }, $originalOrder->getDeliveries()?->getElements() ?? []);

        $transactions = array_map(function ($transaction) {
            return [
                'amount' => $transaction->getAmount()->jsonSerialize(),
                'paymentMethodId' => $transaction->getPaymentMethodId(),
                'stateId' => $transaction->getStateId(),
            ];
        }, $originalOrder->getTransactions()?->getElements() ?? []);

        $orderCustomer = $originalOrder->getOrderCustomer();
        
        if ($orderCustomer === null) {
            throw new \RuntimeException('Order customer is required for creating a new order');
        }

        $newOrderData = [
            'salesChannelId' => $originalOrder->getSalesChannelId(),
            'orderNumber' => $newOrderNumber,
            'billingAddressId' => $originalOrder->getBillingAddressId(),
            'currencyId' => $originalOrder->getCurrencyId(),
            'price' => $originalOrder->getPrice(),
            'shippingCosts' => $originalOrder->getShippingCosts(),
            'orderDateTime' => new \DateTime(),
            'orderCustomer' => [
                'customerId' => $orderCustomer->getCustomerId(),
                'email' => $orderCustomer->getEmail(),
                'firstName' => $orderCustomer->getFirstName(),
                'lastName' => $orderCustomer->getLastName(),
                'salutationId' => $orderCustomer->getSalutationId(),
            ],
            'deepLinkCode' => bin2hex(random_bytes(16)),
            'ruleIds' => $originalOrder->getRuleIds(),
            'currencyFactor' => $originalOrder->getCurrencyFactor() ?? 1.0,
            'itemRounding' => $originalOrder->getItemRounding()?->jsonSerialize(),
            'totalRounding' => $originalOrder->getTotalRounding()?->jsonSerialize(),
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

        if ($newOrder === null) {
            throw new \Exception('Failed to create a new order');
        }

        $newOrder->addExtension('quantities', new ArrayEntity(['quantities' => array_column($lineItems, 'quantity')]));

        return $newOrder;
    }

    /**
     * @param PaymentTransactionStruct $transaction
     * @param Context $context
     * @param array<string, mixed> $extraParams
     * @param OrderTransactionEntity $orderTransaction
     * @param OrderEntity $order
     * @return string
     * @throws GuzzleException
     * @throws Exception
     */
    public function getLink(
        PaymentTransactionStruct $transaction,
        Context $context,
        OrderTransactionEntity $orderTransaction,
        OrderEntity $order,
        array $extraParams = []
    ): string {
        $returnUrl = $transaction->getReturnUrl();

        $callbackUrl = str_replace('finalize-transaction', 'quickpay-finalize-transaction', (string)$returnUrl);

        /** @var array<string,mixed>|null $customFields */
        $customFields = $order->getCustomFields();
        if (!\is_array($customFields)) {
            $customFields = [];
        }

        /** @var array<string,mixed>|null $subscriptionResponse */
        $subscriptionResponse = null;
        if (isset($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD])) {
            $subscriptionResponse = \json_decode((string) $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD], true);
            if (!\is_array($subscriptionResponse)) {
                $subscriptionResponse = null;
            }
        }

        $salesChannel   = $order->getSalesChannel();
        $languageId     = $salesChannel?->getLanguageId();

        $language = 'en';
        if (\is_string($languageId) && $languageId !== '') {
            $language = $this->getLanguage($languageId, $context);
        }

        $amountCents = ($order->getAmountTotal() * 100);

        $updateFormParams = [
            'amount'       => $amountCents,
            'continue_url' => $callbackUrl . '&status=accepted',
            'cancel_url'   => $callbackUrl . '&status=cancel',
            'callback_url' => $callbackUrl,
            'language'     => $language,
        ];

        if ($extraParams !== []) {
            /** @var array<string,mixed> $updateFormParams */
            $updateFormParams = array_merge($updateFormParams, $extraParams);
        }

        /** @var array<string,mixed>|null $subscriptionResponse */
        $subId = (is_array($subscriptionResponse) && isset($subscriptionResponse['id']))
            ? (string) $subscriptionResponse['id']
            : null;

        if ($subId === null) {
            // choose: throw, return, or log
            throw new \RuntimeException('Missing Quickpay subscription id on order ' . $order->getOrderNumber());
        }

        $linkResponse = $this->getClient($order->getSalesChannelId())
            ->request('PUT', 'subscriptions/' . $subId . '/link', [
                'form_params' => $updateFormParams,
            ]);

        if ($linkResponse->getStatusCode() !== 200) {
            throw new Exception(
                $linkResponse->getBody()->getContents()
                . $order->getOrderNumber()
            );
        }

        $linkResponseContent = json_decode($linkResponse->getBody()->getContents(), true);

        if (!isset($linkResponseContent['url'])) {
            throw new Exception(
                $linkResponse->getBody()->getContents()
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
            ]
        );

        return $linkResponseContent['url'];
    }

    /**
     * @throws GuzzleException
     * @throws \DateMalformedStringException
     */
    public function recurring(
        string $orderId,
        Context $context,
        ?bool $initial = false
    ): void {

        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('deliveries');
        $criteria->addAssociation('salesChannel.domains');

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search(
            $criteria,
            $context
        )->first();

        // TODO: Send emails to shop admin on payment error
        if ($order === null) {
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

        $transaction = null;
        $transactions = $order->getTransactions();

        if ($transactions !== null) {
            foreach ($states as $state) {
                $transaction = $transactions->filterByState($state)->first();
                if ($transaction !== null) {
                    break;
                }
            }
        }

        if ($transaction === null) {
            return;
        }

        $customFields = $order->getCustomFields();
        if ($customFields === null || !isset($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD])) {
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
            ->format(DateTimeInterface::ATOM);

        // We're adding a -S to the orderId for the subscription, as the recurring payment will use the orderId.
        if ($initial === true) {
            $recurringOrderNumber = $order->getOrderNumber() . '-S-Initial';
        } else {
            $recurringOrderNumber = $order->getOrderNumber() . '-S';
        }

        $data = [
            'amount' => $order->getAmountTotal() * 100,
            'order_id' => $recurringOrderNumber,
            'auto_capture_at' => $autoCaptureAt
        ];

        // A custom callback made for recurring payments, so we can validate them.
        $salesChannel = $order->getSalesChannel();

        $domain = $salesChannel?->getDomains()?->first();
        if ($domain !== null) {
            $baseUrl = rtrim($domain->getUrl(), '/');
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
                    'orderId' => $orderId,
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

        $paymentState = $transaction->getStateMachineState()?->getTechnicalName();
        $orderState = $order->getStateMachineState()?->getTechnicalName();
        $responseBody = $response->getBody()->getContents();
        if ($statusCode === 202 || $statusCode === 200) {
            if ($responseBody === '') {
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
                            'orderId' => $orderId,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                            'errorType' => $e::class
                        ]
                    );
                }

                $responseBody = $response->getBody()->getContents();
            }
            $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD] = $responseBody;
            $this->setOrderCustomFields($order->getId(), $customFields, $context);

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

    /**
     * @return array<string, mixed>
     * @throws GuzzleException
     */
    public function checkSubscriptionStatusByOrderId(
        string $orderId,
        SalesChannelContext $salesChannelContext
    ): array {
        $subscriptionResponse = $this->getClient($salesChannelContext->getSalesChannelId())
            ->request('GET', 'subscriptions', [
                'query' => [
                    'order_id' => $orderId
                ]
            ]);

        $content = $subscriptionResponse->getBody()->getContents();
        return json_decode($content, true);
    }
}
