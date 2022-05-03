<?php declare(strict_types=1);

namespace Wexo\Quickpay\Service;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\System\StateMachine\Transition;
use Wexo\Quickpay\ServiceInterface\QuickpayInterface;
use Wexo\Quickpay\WexoQuickpay;

/**
 * Class PaymentService
 * @package Wexo\Quickpay\Service
 */
class PaymentQuickpayService extends QuickpayService implements QuickpayInterface
{
    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param SalesChannelContext $salesChannelContext
     * @throws GuzzleException
     */
    public function create(
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

            $taxRate = 0;

            if ($orderLineItem->getPrice()->getTaxRules()->first()) {
                $taxRate = $orderLineItem->getPrice()->getTaxRules()->first()->getTaxRate() / 100;
            }

            $basket[] = [
                'qty' => $orderLineItem->getQuantity(),
                'item_no' => $itemNo,
                'item_name' => $orderLineItem->getLabel(),
                'item_price' => $orderLineItem->getUnitPrice() * 100,
                'vat_rate' => $taxRate,
            ];
        }

        $shippingTotal = $order->getShippingTotal();

        $shippingTaxRate = 0;
        if ($order->getShippingCosts()->getTaxRules()->first()->getTaxRate()) {
            $shippingTaxRate = $order->getShippingCosts()->getTaxRules()->first()->getTaxRate() / 100;
        }

        if ($shippingTotal) {
            $basket[] = [
                'qty' => 1,
                'item_no' => 'Shipping',
                'item_name' => 'Shipping',
                'item_price' => $shippingTotal * 100,
                'vat_rate' => $shippingTaxRate,
            ];
        }

        $currency = $salesChannelContext->getCurrency()->getIsoCode();

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

        $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD] = $content;
        $this->setOrderCustomFields($order->getId(), $customFields);
        $transaction->getOrder()->setCustomFields($customFields);
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param SalesChannelContext $salesChannelContext
     * @return string
     * @throws GuzzleException
     */
    public function getLink(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext
    ): string {
        $returnUrl = $transaction->getReturnUrl();

        $callbackUrl = str_replace('finalize-transaction', 'quickpay-finalize-transaction', $returnUrl);

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

        $order = $transaction->getOrder();
        $paymentHandler = $transaction->getOrderTransaction()->getPaymentMethod()->getHandlerIdentifier();
        if ($paymentHandler === MobilepayPayment::class) {
            $updateFormParams['payment_methods'] = 'mobilepay';
        } elseif ($paymentHandler === KlarnaPayment::class) {
            $updateFormParams['payment_methods'] = 'klarna-payments';
        } elseif ($paymentHandler == ViabillPayment::class) {
            $updateFormParams['payment_methods'] = 'viabill';
        } elseif ($paymentHandler == SwishPayment::class) {
            $updateFormParams['payment_methods'] = 'swish';
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
     * @param float|null $amount
     * @return bool|null
     * @throws GuzzleException
     */
    public function capture(
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

        if (! $transaction) {
            return false;
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

        // On swish payment, skip trying to capture and update Shopware states for Shipping and Order
        if ($paymentResponse->acquirer === 'swish') {
            $this->swishPaymentUpdateOrderStates($order, $context);

            return true;
        }

        // TODO: The customer could go into QuickPay and withdraw manually.
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

            $quickPayResponse = json_decode($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD]);
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
     * @param OrderEntity $order
     * @throws GuzzleException
     */
    public function cancel(OrderEntity $order): void
    {
        $customFields = $order->getCustomFields();
        $paymentResponse = \json_decode($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD], true);
        $id = $paymentResponse['id'] ?? null;
        $accepted = $paymentResponse['accepted'] ?? false;
        if ($id && $accepted) {
            $this->getClient($order->getSalesChannelId())->request('POST', 'payments/' . $id . "/cancel");
        }
    }

    /**
     * @param \stdClass $quickpayResponse
     * @return float
     */
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

    /**
     * On swish payment, skip trying to capture and update Shopware states for Shipping and Order
     * @param OrderEntity $order
     * @param Context $context
     * @return void
     */
    private function swishPaymentUpdateOrderStates(OrderEntity $order, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(
                OrderDefinition::ENTITY_NAME,
                $order->getId(),
                StateMachineTransitionActions::ACTION_COMPLETE,
                'stateId'
            ),
            $context
        );

        $this->stateMachineRegistry->transition(
            new Transition(
                OrderDeliveryDefinition::ENTITY_NAME,
                $order->getDeliveries()->first()->getId(),
                StateMachineTransitionActions::ACTION_SHIP,
                'stateId'
            ),
            $context
        );
    }
}
