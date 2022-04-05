<?php declare(strict_types=1);

namespace Wexo\Quickpay\Subscriber;

use GuzzleHttp\Exception\GuzzleException;
use Shopware\Core\Checkout\Cart\Order\OrderConvertedEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Wexo\Quickpay\Service\SubscriptionQuickpayService;
use Wexo\Quickpay\ServiceInterface\QuickpayInterface;

/**
 * Class OrderDetailSubscriber
 * @package Wexo\Quickpay\Subscriber
 */
class OrderDetailSubscriber implements EventSubscriberInterface
{
    protected EntityRepositoryInterface $orderRepository;
    protected SystemConfigService $systemConfigService;
    protected QuickpayInterface $paymentService;
    protected SubscriptionQuickpayService $subscriptionQuickpayService;

    /**
     * @param EntityRepositoryInterface $orderRepository
     * @param SystemConfigService $systemConfigService
     * @param QuickpayInterface $paymentService
     */
    public function __construct(
        EntityRepositoryInterface $orderRepository,
        SystemConfigService $systemConfigService,
        QuickpayInterface $paymentService,
        SubscriptionQuickpayService $subscriptionQuickpayService
    ) {
        $this->orderRepository = $orderRepository;
        $this->systemConfigService = $systemConfigService;
        $this->paymentService = $paymentService;
        $this->subscriptionQuickpayService = $subscriptionQuickpayService;
    }

    /**
     * @return array|string[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            StateMachineTransitionEvent::class => 'onStateMachineTransitionEvent',
            OrderConvertedEvent::class => 'orderConvertedEvent'
        ];
    }

    /**
     * @param StateMachineTransitionEvent $event
     * @throws GuzzleException
     */
    public function onStateMachineTransitionEvent(StateMachineTransitionEvent $event)
    {
        $eventName = $event->getToPlace()->getTechnicalName();
        $transactionId = $event->getEntityId();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('transactions.id', $transactionId));

        /** @var OrderEntity $orderTransaction */
        $order = $this->orderRepository->search(
            $criteria,
            $event->getContext()
        )->first();

        $capture = $event->getContext()->getExtension('capture');
        if ($order) {
            if ($eventName === OrderTransactionStates::STATE_CANCELLED) {
                $this->paymentService->cancel($order);
            }

            if ($capture && ! $capture->get('amount')) {
                return;
            }

            if ($eventName === OrderTransactionStates::STATE_PAID) {
                $this->paymentService->capture($order->getId());
            }

            if ($eventName === OrderTransactionStates::STATE_PARTIALLY_PAID &&
                $capture && $capture->get('amount')
            ) {
                $this->paymentService->capture(
                    $order->getId(),
                    (float) $capture->get('amount')
                );
            }
        }
    }

    /**
     * @param OrderConvertedEvent $event
     * @return void
     * @throws GuzzleException
     */
    public function orderConvertedEvent(OrderConvertedEvent $event)
    {
        $subscription = $event->getContext()->getExtension('subscriptionOrder');
        if ($subscription) {
            $this->subscriptionQuickpayService->recurring($event->getOrder()->getId());
        }
    }
}
