<?php declare(strict_types=1);

namespace Wexo\Quickpay\Subscriber;

use Error;
use Monolog\Logger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use stdClass;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Wexo\Quickpay\Service\QuickpayPayment;
use Wexo\Quickpay\WexoQuickpay;

/**
 * Class OrderDetailSubscriber
 * @package Wexo\Quickpay\Subscriber
 */
class OrderDetailSubscriber implements EventSubscriberInterface
{
    /**
     * @var EntityRepositoryInterface $orderRepository
     */
    protected $orderRepository;
    /**
     * @var SystemConfigService $systemConfigService
     */
    protected $systemConfigService;
    /**
     * @var QuickpayPayment $quickpayPaymentService
     */
    protected $quickpayPaymentService;

    /**
     * OrderDetailSubscriber constructor.
     * @param EntityRepositoryInterface $orderRepository
     * @param SystemConfigService $systemConfigService
     * @param QuickpayPayment $quickpayPaymentService
     */
    public function __construct(
        EntityRepositoryInterface $orderRepository,
        SystemConfigService $systemConfigService,
        QuickpayPayment $quickpayPaymentService
    ) {
        $this->orderRepository = $orderRepository;
        $this->systemConfigService = $systemConfigService;
        $this->quickpayPaymentService = $quickpayPaymentService;
    }

    /**
     * @return array|string[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            StateMachineTransitionEvent::class => 'onStateMachineTransitionEvent'
        ];
    }

    /**
     * @param StateMachineTransitionEvent $event
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
                $this->quickpayPaymentService->cancelPayment($order);
            }

            if ($capture && ! $capture->get('amount')) {
                return;
            }

            if ($eventName === OrderTransactionStates::STATE_PAID) {
                $this->quickpayPaymentService->capturePayment($order->getId());
            }

            if ($eventName === OrderTransactionStates::STATE_PARTIALLY_PAID &&
                $capture && $capture->get('amount')
            ) {
                $this->quickpayPaymentService->capturePayment(
                    $order->getId(),
                    (float) $capture->get('amount')
                );
            }
        }
    }
}
