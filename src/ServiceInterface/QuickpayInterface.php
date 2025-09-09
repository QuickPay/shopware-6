<?php
namespace Wexo\Quickpay\ServiceInterface;

use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
//use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Context;

interface QuickpayInterface
{
    public function create(
        PaymentTransactionStruct $transaction,
        Context $context
    ): void;

    public function getLink(
        PaymentTransactionStruct $transaction,
        Context $context,
        Array $extraParams = []
    ): string;
}
