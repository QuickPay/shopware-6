<?php
namespace Wexo\Quickpay\ServiceInterface;

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
        Array $extraParams = []
    ): string;
}
