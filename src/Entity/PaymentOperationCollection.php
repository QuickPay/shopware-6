<?php


declare(strict_types=1);

namespace QuickPay\Entity;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void              add(PaymentOperationEntity $entity)
 * @method void              set(string $key, PaymentOperationEntity $entity)
 * @method PaymentOperationEntity[]    getIterator()
 * @method PaymentOperationEntity[]    getElements()
 * @method PaymentOperationEntity|null get(string $key)
 * @method PaymentOperationEntity|null first()
 * @method PaymentOperationEntity|null last()
 */
class PaymentOperationCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return PaymentOperationEntity::class;
    }
}
