<?php


declare(strict_types=1);

namespace QuickPay\Entity;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void              add(PaymentEntity $entity)
 * @method void              set(string $key, PaymentEntity $entity)
 * @method PaymentEntity[]    getIterator()
 * @method PaymentEntity[]    getElements()
 * @method PaymentEntity|null get(string $key)
 * @method PaymentEntity|null first()
 * @method PaymentEntity|null last()
 */
class PaymentCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return PaymentEntity::class;
    }
}
