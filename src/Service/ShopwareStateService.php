<?php declare(strict_types=1);

namespace Wexo\Quickpay\Service;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

/**
 * Class SubscriptionService
 * @package Wexo\Quickpay\Service
 */
class ShopwareStateService
{
    protected OrderTransactionStateHandler $transactionStateHandler;
    protected StateMachineRegistry $stateMachineRegistry;

    /**
     * @param OrderTransactionStateHandler $transactionStateHandler
     * @param StateMachineRegistry $stateMachineRegistry
     */
    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        StateMachineRegistry $stateMachineRegistry
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->stateMachineRegistry = $stateMachineRegistry;
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
        string $orderState
    ) {
        $context = Context::createDefaultContext();
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

    /**
     * @param string $transactionId
     * @param string $orderId
     * @param string $paymentState
     * @param string $orderState
     * @return void
     */
    public function paid(
        string $transactionId,
        string $orderId,
        string $paymentState,
        string $orderState
    ): void {
        $context = Context::createDefaultContext();
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
