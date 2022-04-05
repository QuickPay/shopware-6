<?php

namespace Wexo\Quickpay\Helper;

use Shopware\Core\Checkout\Order\OrderEntity;
use Wexo\Quickpay\WexoQuickpay;

class ServiceHelper
{
    /**
     * @param OrderEntity $order
     * @return bool
     */
    public static function isOrderSubscription(OrderEntity $order)
    {
        $customFields = $order->getCustomFields() ?? [];
        if (isset($customFields[WexoQuickpay::QUICKPAY_SUBSCRIPTION_ID])) {
            return true;
        } elseif ($order->getLineItems()) {
            foreach ($order->getLineItems() as $lineItem) {
                $payload = $lineItem->getPayload();
                $subscription = $payload['subscription'] ?? [];
                if ($subscription) {
                    return true;
                }
            }
        }

        return false;
    }
}
