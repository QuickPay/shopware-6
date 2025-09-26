<?php declare(strict_types=1);

namespace Wexo\Quickpay\Subscriber;

use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use GuzzleHttp\Exception\GuzzleException;
use Shopware\Core\Checkout\Cart\Order\OrderConvertedEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Wexo\Quickpay\Service\PaymentQuickpayService;
use Wexo\Quickpay\Service\SubscriptionQuickpayService;
use Wexo\Quickpay\Service\SwishPayment;

class OrderDetailSubscriber implements EventSubscriberInterface
{
    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     * @param SystemConfigService $systemConfigService
     * @param PaymentQuickpayService $paymentService
     * @param SubscriptionQuickpayService $subscriptionQuickpayService
     */
    public function __construct(
        protected EntityRepository $orderRepository,
        protected SystemConfigService $systemConfigService,
        protected PaymentQuickpayService $paymentService,
        protected SubscriptionQuickpayService $subscriptionQuickpayService
    ) {
    }

    /**
     * @return array<string, string|list<array{0: string, 1?: int}|int|string>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            StateMachineTransitionEvent::class => 'onStateMachineTransitionEvent',
            OrderConvertedEvent::class => 'orderConvertedEvent'
        ];
    }

    /**
     * @throws GuzzleException
     */
    public function onStateMachineTransitionEvent(StateMachineTransitionEvent $event): void
    {
        $eventName = $event->getToPlace()->getTechnicalName();
        $relevantEvent = in_array(
            $eventName,
            [
                OrderTransactionStates::STATE_CANCELLED,
                OrderTransactionStates::STATE_PARTIALLY_PAID,
                OrderTransactionStates::STATE_PAID
            ],
            true
        );
        // Do not waste compute time fetching config or orders if the event should not be handled anyway
        if (!$relevantEvent) {
            return;
        }

        $capturePayments = $this->systemConfigService->getBool('WexoQuickpay.config.quickpayCaptureOnOrderPayment');
        $cancelPayments = $this->systemConfigService->getBool('WexoQuickpay.config.quickpayCancelPaymentOnOrderCancel');

        // If we shouldn't modify payment status in QuickPay at all, no need to waste computing time
        if (!$capturePayments && !$cancelPayments) {
            return;
        }
        if ($eventName === OrderTransactionStates::STATE_CANCELLED && !$cancelPayments) {
            return;
        }
        if (($eventName === OrderTransactionStates::STATE_PAID
                || $eventName === OrderTransactionStates::STATE_PARTIALLY_PAID)
            && !$capturePayments
        ) {
            return;
        }

        $transactionId = $event->getEntityId();

        $criteria = new Criteria();
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->addFilter(new EqualsFilter('transactions.id', $transactionId));
        $criteria->addFilter(new ContainsFilter('transactions.paymentMethod.handlerIdentifier', 'Quickpay'));

        /** @var OrderEntity $order */
        $order = $this->orderRepository->search(
            $criteria,
            $event->getContext()
        )->first();

        /** @var ArrayStruct|null $capture */
        $capture = $event->getContext()->getExtension('capture');

        if ($order !== null) {
            if ($eventName === OrderTransactionStates::STATE_CANCELLED) {
                $this->paymentService->cancel($order);
            }

            if ($capture !== null && $capture->get('amount') === null) {
                return;
            }
            
            if ($eventName === OrderTransactionStates::STATE_PAID) {
                $transaction = $order->getTransactions()?->first();
                $paymentHandler = $transaction?->getPaymentMethod()?->getHandlerIdentifier();

                if ($paymentHandler === null) {
                    return;
                }

                // We don't want to try and capture on a swishpayment as Swish orders
                // are set as Paid as soon as Quickpay response is accepted.
                // When using Capture API on Swish payments, it is then set to order Status: Done and Delivery: Shipped
                if ($paymentHandler !== SwishPayment::class) {
                    $this->paymentService->capture(
                        $order->getId(),
                        $event->getContext(),
                    );
                }
            }

            if ($eventName === OrderTransactionStates::STATE_PARTIALLY_PAID &&
                $capture !== null && $capture->get('amount') !== null
            ) {
                $this->paymentService->capture(
                    $order->getId(),
                    $event->getContext(),
                    (float) $capture->get('amount')
                );
            }
        }
    }

    /**
     * @return void
     * @throws GuzzleException
     */
    public function orderConvertedEvent(OrderConvertedEvent $event)
    {
        $subscription = $event->getContext()->getExtension('subscriptionOrder');
        if ($subscription !== null) {
            $this->subscriptionQuickpayService->recurring($event->getOrder()->getId());
        }
    }
}
