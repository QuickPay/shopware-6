<?php

namespace Wexo\Quickpay\Helper;

use Shopware\Core\Checkout\Order\OrderEntity;
use Wexo\Quickpay\WexoQuickpay;

class ServiceHelper
{
    public static function isOrderSubscription(OrderEntity $order): bool
    {
        $customFields = $order->getCustomFields() ?? [];
        if (isset($customFields[WexoQuickpay::QUICKPAY_SUBSCRIPTION_ID])) {
            return true;
        } elseif ($order->getLineItems() !== null) {
            foreach ($order->getLineItems() as $lineItem) {
                $payload = $lineItem->getPayload();
                if (isset($payload['subscription'])) {
                    return true;
                }
            }
        }

        return false;
    }
}
