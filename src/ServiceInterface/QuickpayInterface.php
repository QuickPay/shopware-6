<?php
namespace Wexo\Quickpay\ServiceInterface;

use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

interface QuickpayInterface
{
    public function create(
        AsyncPaymentTransactionStruct &$transaction,
        SalesChannelContext $salesChannelContext
    ): void;

    public function getLink(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext,
        Array $extraParams = []
    ): string;
}
