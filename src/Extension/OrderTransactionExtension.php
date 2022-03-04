<?php


namespace QuickPay\Extension;

use QuickPay\Entity\PaymentDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class OrderTransactionExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new OneToOneAssociationField('quickpayPayment', 'id', 'transaction_id', PaymentDefinition::class, false))
                ->addFlags(new CascadeDelete())
        );
    }
    
    public function getDefinitionClass(): string
    {
        return OrderTransactionDefinition::class;
    }
}
