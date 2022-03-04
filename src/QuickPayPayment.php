<?php


declare(strict_types=1);

namespace QuickPay;

use QuickPay\Service\CreditCardMethod;
use QuickPay\Service\KlarnaMethod;
use QuickPay\Service\PayPalMethod;

class QuickPayPayment extends PaymentPlugin
{
    protected function getPaymentMethodClasses(): array
    {
        return [
            CreditCardMethod::class,
            PayPalMethod::class,
            KlarnaMethod::class
        ];
    }
}
