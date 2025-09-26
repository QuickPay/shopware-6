<?php
namespace Wexo\Quickpay\ServiceInterface;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;

interface QuickpayInterface
{
    public function create(
        PaymentTransactionStruct $transaction,
        Context $context
    ): void;


    /**
     * @param array<string,mixed> $extraParams
     */
    public function getLink(
        PaymentTransactionStruct $transaction,
        Context $context,
        Array $extraParams = [],
        ?OrderTransactionEntity $tx = null,
        ?OrderEntity $order = null
    ): string;
}
