<?php declare(strict_types=1);

namespace Wexo\Quickpay\Subscriber;

use Shopware\Core\Checkout\Payment\Event\FinalizePaymentOrderTransactionCriteriaEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class FinalizePaymentOrderTransactionCriteriaSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            FinalizePaymentOrderTransactionCriteriaEvent::class => 'onFinalizePaymentOrderTransactionCriteria',
        ];
    }

    public function onFinalizePaymentOrderTransactionCriteria(FinalizePaymentOrderTransactionCriteriaEvent $event): void
    {
        $criteria = $event->getCriteria();
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('order.stateMachineState');
    }
}
