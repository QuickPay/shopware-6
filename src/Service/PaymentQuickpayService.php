<?php declare(strict_types=1);

namespace Wexo\Quickpay\Service;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\System\StateMachine\Transition;
use stdClass;
use Wexo\Quickpay\ServiceInterface\QuickpayInterface;
use Wexo\Quickpay\WexoQuickpay;

class PaymentQuickpayService extends QuickpayService implements QuickpayInterface
{
    /**
     * @param PaymentTransactionStruct $transaction
     * @param Context $context
     * @throws GuzzleException
     */
    public function create(
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        $tx = $this->loadTransaction($transaction->getOrderTransactionId(), $context);
        $order = $tx->getOrder();

        $basket = [];

        if (!($order instanceof OrderEntity)) {
            return;
        }

        $lineItems = $order->getLineItems() ?? new OrderLineItemCollection();

        foreach ($lineItems as $orderLineItem) {
            $payload = $orderLineItem->getPayload() ?? [];
            $itemNo = (count($payload) > 0 && isset($payload['productNumber']))
                ? $payload['productNumber']
                : $orderLineItem->getLabel();

            $price = $orderLineItem->getPrice();

            $taxRate = 0;

            if ($price !== null) {
                $firstRule = $price->getTaxRules()->first();
                if ($firstRule !== null) {
                    $taxRate = $firstRule->getTaxRate() / 100;
                }
            }

            $unitPrice = $price?->getUnitPrice() ?? 0.0;

            $basket[] = [
                'qty' => $orderLineItem->getQuantity(),
                'item_no' => $itemNo,
                'item_name' => $orderLineItem->getLabel(),
                'item_price' => (int) round($unitPrice * 100),
                'vat_rate' => $taxRate,
            ];
        }

        $shippingTotal = $order->getShippingTotal();

        $shippingTaxRate = 0;
        $taxRules = $order->getShippingCosts()->getTaxRules();
        $firstRule = $taxRules->first();
        if ($firstRule !== null && $firstRule->getTaxRate() > 0) {
            $shippingTaxRate = $firstRule->getTaxRate() / 100;
        }

        if ($shippingTotal > 0) {
            $basket[] = [
                'qty' => 1,
                'item_no' => 'Shipping',
                'item_name' => 'Shipping',
                'item_price' => $shippingTotal * 100,
                'vat_rate' => $shippingTaxRate,
            ];
        }

        $currencyEntity = $order->getCurrency();
        if ($currencyEntity === null) {
            throw new Exception('Currency not found for order');
        }
        $currency = $currencyEntity->getIsoCode();

        $formParams = [
            'currency' => $currency,
            'order_id' => $order->getOrderNumber(),
            'basket' => $basket
        ];

        $paymentResponse = $this->getClient($order->getSalesChannelId())
            ->request('POST', 'payments', [
                'json' => $formParams
            ]);

        if ($paymentResponse->getStatusCode() !== 201) {
            $errorMessage = $paymentResponse->getBody()->getContents();
            if (empty($errorMessage)) {
                $errorMessage = 'Failed to create payment for order ' . ($formParams['order_id'] ?? 'unknown');
            }
            throw new Exception($errorMessage);
        }

        $content = $paymentResponse->getBody()->getContents();

        $customFields = [];
        $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD] = $content;
        $this->setOrderCustomFields($order->getId(), $customFields);
        $order->setCustomFields($customFields);
    }

    /**
     * @param PaymentTransactionStruct $transaction
     * @param Context $context
     * @param array<string, mixed> $extraParams
     * @return string
     * @throws GuzzleException
     */
    public function getLink(
        PaymentTransactionStruct $transaction,
        Context $context,
        array $extraParams = []
    ): string {
        $returnUrl = $transaction->getReturnUrl() ?? '';

        $callbackUrl = str_replace('finalize-transaction', 'quickpay-finalize-transaction', $returnUrl);

        $tx    = $this->loadTransaction($transaction->getOrderTransactionId(), $context);
        $order = $tx->getOrder();
        if ($order === null) {
            throw new Exception('Order not found');
        }

        $updateFormParams = [
            'amount' => (int) \round($order->getAmountTotal() * 100),
            'continue_url' => $callbackUrl . '&status=accepted',
            'cancel_url' => $callbackUrl . '&status=cancel',
            'callback_url' => $callbackUrl,
            'language' => $this->getLanguage($order->getLanguageId(), $context),
        ];

        if (count($extraParams) > 0) {
            $updateFormParams = array_merge($updateFormParams, $extraParams);
        }

        $identifier = $tx->getPaymentMethod()?->getHandlerIdentifier();
        if ($identifier === null) {
            throw new Exception('Payment method or handler identifier not found');
        }

        $quickpayName = constant($identifier . '::quickpayName');
        $updateFormParams['payment_methods'] = $quickpayName;
        $customFields     = $order->getCustomFields() ?? [];
        $paymentResponse  = \json_decode((string) ($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD] ?? ''), true);

        $linkResponse = $this->getClient($order->getSalesChannelId())
            ->request('PUT', 'payments/' . $paymentResponse['id'] . '/link', [
                'form_params' => $updateFormParams,
            ]);

        if ($linkResponse->getStatusCode() !== 200) {
            $errorMessage = $linkResponse->getBody()->getContents();
            if (empty($errorMessage)) {
                $errorMessage = 'Failed to link payment for order ' . $order->getOrderNumber();
            }
            throw new Exception($errorMessage);
        }

        $linkResponseContent = json_decode($linkResponse->getBody()->getContents(), true);

        if (!isset($linkResponseContent['url'])) {
            $errorMessage = $linkResponse->getBody()->getContents();
            if (empty($errorMessage)) {
                $errorMessage = 'Failed to link payment for order ' . $order->getOrderNumber();
            }
            throw new Exception($errorMessage);
        }

        $this->paymentLogger(
            WexoQuickpay::ORDER_CREATE_SUCCESS,
            [
                'orderId' => $order->getOrderNumber(),
                'updateFormParams' => $updateFormParams,
                'paymentResponse' => $paymentResponse,
                'linkResponse' => $linkResponseContent
            ]
        );

        return $linkResponseContent['url'];
    }

    /**
     * @throws GuzzleException
     */
    public function capture(
        string $orderId,
        Context $context,
        ?float $amount = null
    ): ?bool {
        $context->addExtension('capture', new ArrayStruct([
            'amount' => false
        ]));

        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('deliveries');
        $criteria->addAssociation('transactions.stateMachineState');

        /** @var OrderEntity $order */
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

            return null;
        }

        $states = [
            OrderTransactionStates::STATE_PAID,
            OrderTransactionStates::STATE_PARTIALLY_PAID,
            OrderTransactionStates::STATE_AUTHORIZED
        ];

        foreach ($states as $state) {
            $transaction = $order->getTransactions()->filterByState($state)->first();
            if ($transaction !== null) {
                break;
            }
        }

        if ($transaction === null) {
            return false;
        }

        $paymentResponse = $this->updateResponse($orderId);
        $paymentResponse = $paymentResponse !== '' && $paymentResponse !== null
            ? json_decode($paymentResponse)
            : null;
        if ($paymentResponse === null
            || $paymentResponse === false
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
        if ($paymentResponse->order_id !== $order->getOrderNumber()) {
            return null;
        }

        // On swish payment, skip trying to capture and update Shopware states for Shipping and Order
        if ($paymentResponse->acquirer === 'swish') {
            $this->swishPaymentUpdateOrderStates($order, $context);

            return true;
        }

        $availableAmount = $this->getAvailableAmount($paymentResponse);
        if ($amount === null || $amount === 0.0) {
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
                $logEntry
            );

            if ($responseBody === '') {
                $responseBody = $this->updateResponse($orderId, $paymentResponse->id);
            } else {
                $customFields = [];
                $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD] = $responseBody;
                $this->setOrderCustomFields($orderId, $customFields);
            }

            $availableAmount = $this->getAvailableAmount(json_decode($responseBody)) - (float) $amount;
            $stateName = $transaction->getStateMachineState()->getTechnicalName();
            if ($availableAmount === 0.0 && $stateName !== OrderTransactionStates::STATE_PAID) {
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
            $customFields = $order->getCustomFields() ?? [];

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

    /**
     * @throws GuzzleException
     */
    public function cancel(OrderEntity $order): void
    {
        $customFields = $order->getCustomFields();
        $paymentResponse = \json_decode((string) $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD], true);
        $id = $paymentResponse['id'] ?? null;
        if ($id) {
            $this->getClient($order->getSalesChannelId())->request('POST', 'payments/' . $id . "/cancel");
        }
    }

    /**
     * @param stdClass $quickpayResponse
     * @return float
     */
    private function getAvailableAmount(stdClass $quickpayResponse): float
    {
        $capturedAmount   = 0.0;
        $authorizedAmount = 0.0;

        $ops = $quickpayResponse->operations ?? null;
        if (!is_iterable($ops)) {
            return 0.0;
        }

        /** @var object{type:string, amount:int|float, qp_status_msg?:string, aq_status_msg?:string} $operation */
        foreach ($ops as $operation) {
            if (!is_object($operation)) {
                continue;
            }

            $approved = (($operation->qp_status_msg ?? null) === 'Approved')
                || (($operation->aq_status_msg ?? null) === 'Approved');
            if (!$approved || !isset($operation->type, $operation->amount)) {
                continue;
            }

            $type   = (string) $operation->type;
            $amount = (float) $operation->amount;

            switch ($type) {
                case 'capture':
                    $capturedAmount += $amount;
                    break;

                case 'authorize':
                case 'recurring':
                    $authorizedAmount += $amount;
                    break;

                default:
                    // ignore unknown operation types
                    break;
            }
        }

        return $authorizedAmount - $capturedAmount;
    }

    /**
     * On swish payment, skip trying to capture and update Shopware states for Shipping and Order
     */
    private function swishPaymentUpdateOrderStates(OrderEntity $order, Context $context): void
    {
        $orderState = $order->getStateMachineState()?->getTechnicalName();

        if ($orderState === OrderStates::STATE_OPEN) {
            $this->stateMachineRegistry->transition(
                new Transition(
                    OrderDefinition::ENTITY_NAME,
                    $order->getId(),
                    StateMachineTransitionActions::ACTION_PROCESS,
                    'stateId'
                ),
                $context
            );
        }

        $this->stateMachineRegistry->transition(
            new Transition(
                OrderDefinition::ENTITY_NAME,
                $order->getId(),
                StateMachineTransitionActions::ACTION_COMPLETE,
                'stateId'
            ),
            $context
        );

        $deliveryId = $order->getDeliveries()?->first()?->getId();

        if ($deliveryId !== null && $deliveryId !== '') {
            $this->stateMachineRegistry->transition(
                new Transition(
                    OrderDeliveryDefinition::ENTITY_NAME,
                    $deliveryId,
                    StateMachineTransitionActions::ACTION_SHIP,
                    'stateId'
                ),
                $context
            );
        }
    }
}
