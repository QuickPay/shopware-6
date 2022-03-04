<?php


namespace QuickPay\Entity;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\VersionField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class PaymentDefinition extends EntityDefinition
{
    public function getEntityName(): string
    {
        return 'quickpay_payment';
    }
    
    public function getEntityClass(): string
    {
        return PaymentEntity::class;
    }
    
    public function getCollectionClass(): string
    {
        return PaymentCollection::class;
    }
    
    protected function getParentDefinitionClass(): ?string
    {
        return OrderTransactionDefinition::class;
    }
    
    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            new VersionField(),
            (new FkField('transaction_id', 'transactionId', OrderTransactionDefinition::class))->addFlags(new Required()),
            (new ReferenceVersionField(OrderTransactionDefinition::class, 'transaction_version_id'))->addFlags(new Required()),
            (new StringField('quickpay_id', 'quickpayId'))->addFlags(new Required()),
            (new StringField('quickpay_order_id', 'quickpayOrderId'))->addFlags(new Required()),
            (new StringField('link', 'link'))->addFlags(new Required()),
            (new StringField('currency', 'currency'))->addFlags(new Required()),
            new IntField('status', 'status'),
            (new IntField('amount', 'amount'))->addFlags(new Required()),
            new IntField('amount_authorized', 'amountAuthorized'),
            new IntField('amount_captured', 'amountCaptured'),
            new IntField('amount_refunded', 'amountRefunded'),
            new DateTimeField('authorized_at', 'authorizedAt'),
            (new OneToOneAssociationField('transaction', 'transaction_id', 'id', OrderTransactionDefinition::class, false)),
            new OneToManyAssociationField('operations', PaymentOperationDefinition::class, 'quickpay_payment_id'),
        ]);
    }
}
