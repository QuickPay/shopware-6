<?php


namespace QuickPay\Entity;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\VersionField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class PaymentOperationDefinition extends EntityDefinition
{
    public function getEntityName(): string
    {
        return 'quickpay_payment_operation';
    }
    
    public function getEntityClass(): string
    {
        return PaymentOperationEntity::class;
    }
    
    public function getCollectionClass(): string
    {
        return PaymentOperationCollection::class;
    }
    
    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            new VersionField(),
            (new FkField('quickpay_payment_id', 'quickpayPaymentId', PaymentDefinition::class))->addFlags(new Required()),
            (new ReferenceVersionField(PaymentDefinition::class, 'quickpay_payment_version_id'))->addFlags(new Required()),
            (new IntField('quickpay_operation_id', 'quickpayOperationId')),
            (new StringField('type', 'type'))->addFlags(new Required()),
            new StringField('status', 'status'),
            (new IntField('amount', 'amount'))->addFlags(new Required()),
            (new JsonField('raw_json', 'rawJson'))->addFlags(new Required()),
            new ManyToOneAssociationField('payment', 'quickpay_payment_id', PaymentDefinition::class),
        ]);
    }
}
