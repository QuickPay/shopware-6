<?php declare(strict_types=1);

namespace Wexo\Quickpay\Service;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

class ShopwareStateService
{
    public function __construct(
        protected OrderTransactionStateHandler $transactionStateHandler,
        protected StateMachineRegistry $stateMachineRegistry
    ) {
    }

    /**
     * @return void
     */
    public function cancel(
        string $transactionId,
        string $orderId,
        string $paymentState,
        string $orderState,
        Context $context
    ) {
        if ($paymentState !== OrderTransactionStates::STATE_CANCELLED) {
            $this->transactionStateHandler->cancel(
                $transactionId,
                $context
            );
        }

        if ($orderState !== OrderStates::STATE_CANCELLED) {
            $this->stateMachineRegistry->transition(
                new Transition(
                    OrderDefinition::ENTITY_NAME,
                    $orderId,
                    StateMachineTransitionActions::ACTION_CANCEL,
                    'stateId'
                ),
                $context
            );
        }
    }

    public function success(
        string $transactionId,
        string $orderId,
        string $paymentState,
        string $orderState,
        Context $context
    ): void {
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
                $this->stateMachineRegistry->transition(
                    new Transition(
                        OrderDefinition::ENTITY_NAME,
                        $orderId,
                        StateMachineTransitionActions::ACTION_REOPEN,
                        'stateId'
                    ),
                    $context
                );
            }

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
    }

    public function paid(
        string $transactionId,
        string $orderId,
        string $paymentState,
        string $orderState,
        Context $context
    ): void {
        if ($paymentState !== OrderTransactionStates::STATE_PAID) {
            if ($paymentState === OrderTransactionStates::STATE_CANCELLED) {
                $this->transactionStateHandler->reopen(
                    $transactionId,
                    $context
                );
            }

            $this->transactionStateHandler->paid(
                $transactionId,
                $context
            );
        }

        if ($orderState !== OrderStates::STATE_IN_PROGRESS) {
            if ($orderState === OrderStates::STATE_CANCELLED) {
                $this->stateMachineRegistry->transition(
                    new Transition(
                        OrderDefinition::ENTITY_NAME,
                        $orderId,
                        StateMachineTransitionActions::ACTION_REOPEN,
                        'stateId'
                    ),
                    $context
                );
            }

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
    }
}
