<?php declare(strict_types=1);

namespace Wexo\Quickpay\Service;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Class SubscriptionService
 * @package Wexo\Quickpay\Service
 */
class ShopwareStateService
{
    protected OrderTransactionStateHandler $transactionStateHandler;
    protected OrderService $orderService;

    /**
     * @param OrderTransactionStateHandler $transactionStateHandler
     * @param OrderService $orderService
     */
    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        OrderService $orderService
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->orderService = $orderService;
    }

    /**
     * @param string $transactionId
     * @param string $paymentState
     * @param string $orderState
     * @return void
     */
    public function cancel(
        string $transactionId,
        string $orderId,
        string $paymentState,
        string $orderState)
    {
        $context = Context::createDefaultContext();
        if ($paymentState !== OrderTransactionStates::STATE_CANCELLED) {
            $this->transactionStateHandler->cancel(
                $transactionId,
                $context
            );
        }

        if ($orderState !== OrderStates::STATE_CANCELLED) {
            $this->orderService->orderStateTransition(
                $orderId,
                StateMachineTransitionActions::ACTION_CANCEL,
                new ParameterBag(),
                $context
            );
        }
    }

    /**
     * @param string $transactionId
     * @param string $orderId
     * @param string $paymentState
     * @param string $orderState
     * @return void
     */
    public function success(
        string $transactionId,
        string $orderId,
        string $paymentState,
        string $orderState
    ): void {
        $context = Context::createDefaultContext();
        if ($paymentState !== OrderTransactionStates::STATE_AUTHORIZED) {
            if ($paymentState === OrderTransactionStates::STATE_CANCELLED) {
                $this->transactionStateHandler->reopen(
                    $transactionId,
                    $context
                );
            }

            $this->transactionStateHandler->authorize(
                $transactionId,
                $context
            );
        }

        if ($orderState !== OrderStates::STATE_IN_PROGRESS) {
            if ($orderState === OrderStates::STATE_CANCELLED) {
                $this->orderService->orderStateTransition(
                    $orderId,
                    StateMachineTransitionActions::ACTION_REOPEN,
                    new ParameterBag(),
                    $context
                );
            }

            $this->orderService->orderStateTransition(
                $orderId,
                StateMachineTransitionActions::ACTION_PROCESS,
                new ParameterBag(),
                $context
            );
        }
    }
}
