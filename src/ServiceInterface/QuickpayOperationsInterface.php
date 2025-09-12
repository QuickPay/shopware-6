<?php
namespace Wexo\Quickpay\ServiceInterface;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;

interface QuickpayOperationsInterface
{
    public function cancel(OrderEntity $order): void;

    public function capture(string $orderId, Context $context, ?float $amount = null): void;
}
