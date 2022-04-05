<?php declare(strict_types=1);

namespace Wexo\Quickpay\Service;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\Transition;
use Wexo\Quickpay\ServiceInterface\QuickpayInterface;
use Wexo\Quickpay\WexoQuickpay;

/**
 * Class SubscriptionService
 * @package Wexo\Quickpay\Service
 */
class SubscriptionQuickpayService extends QuickpayService implements QuickpayInterface
{
    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param SalesChannelContext $salesChannelContext
     * @return void
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function create(
        AsyncPaymentTransactionStruct &$transaction,
        SalesChannelContext $salesChannelContext
    ): void {
        $order = $transaction->getOrder();

        $currency = $salesChannelContext->getCurrency()->getIsoCode()
            ?? WexoQuickpay::FALLBACK_CURRENCY;

        // We're adding a -S to the orderId for the subscription, as the recurring payment will use the orderId.
        $formParams = [
            'currency' => $currency,
            'order_id' => $order->getOrderNumber() . '-S',
            'description' => 'Order with subscription',
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
            WexoQuickpay::QUICKPAY_SUBSCRIPTION_ID => (string) $responseData['id'],
            WexoQuickpay::QUICKPAY_RESPONSE_FIELD => $content
        ];

        $this->setOrderCustomFields($order->getId(), $customFields);
        $transaction->getOrder()->setCustomFields($customFields);
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param SalesChannelContext $salesChannelContext
     * @return string
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getLink(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext
    ): string {
        $returnUrl = $transaction->getReturnUrl();

        $callbackUrl = str_replace('finalize-transaction', 'quickpay-finalize-transaction', $returnUrl);

        $order = $transaction->getOrder();

        $customFields = $order->getCustomFields();
        $subscriptionResponse = \json_decode($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD], true);

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
     * @param string $orderId
     * @return void
     * @throws GuzzleException
     */
    public function recurring(
        string $orderId
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
        if (! $order) {
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

        if (! $transaction) {
            return;
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

            return;
        }

        $quickPayResponse = json_decode($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD], true);
        $subscriptionId = $customFields[WexoQuickpay::QUICKPAY_SUBSCRIPTION_ID];
        if (! $subscriptionId) {
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

        $data = [
            'amount' => $order->getAmountTotal() * 100,
            'order_id' => $order->getOrderNumber(),
        ];

        // A custom callback made for recurring payments, so we can validate them.
        $domain = $order->getSalesChannel()->getDomains() ? $order->getSalesChannel()->getDomains()->first() : null;
        if ($domain) {
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

        $statusCode = $response->getStatusCode();
        $logEntry = [
            'orderId'            => $orderId,
            'orderNumber'        => $order->getOrderNumber() ?? null,
            'subscriptionId'     => $subscriptionId,
            'responseStatusCode' => $statusCode,
            'response'           => json_decode($response->getBody()->getContents())
        ];

        $paymentState = $transaction->getStateMachineState()->getTechnicalName();
        $orderState = $order->getStateMachineState()->getTechnicalName();
        $responseBody = $response->getBody()->getContents();
        if ($statusCode === 202) {
            if ($responseBody) {
                // Response for recurring does not always have a body (depending on Paymentmethod)
                // If no body in response, get it from quickpay manually.
                $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD] = $responseBody;
                $this->setOrderCustomFields($order->getId(), $customFields);
            } else {
                $location = explode("/", $response->getHeader('Location')[0]);
                $quickpayPaymentId = $location[4];

                try {
                    $response = $this->getClient($order->getSalesChannelId())->request(
                        'GET',
                        'payments/' . $quickpayPaymentId
                    );
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

                $responseBody = $response->getBody()->getContents();
                $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD] = $responseBody;
                $this->setOrderCustomFields($order->getId(), $customFields);
            }

            if ($paymentState === OrderTransactionStates::STATE_OPEN) {
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
}
