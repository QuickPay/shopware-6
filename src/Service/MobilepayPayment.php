<?php declare(strict_types=1);

namespace Wexo\Quickpay\Service;

use GuzzleHttp\Exception\GuzzleException;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Class MobilepayPayment
 * @package Wexo\Quickpay\Service
 */
class MobilepayPayment extends QuickpayPayment
{
    protected static string $quickpayName = 'mobilepay';

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @return RedirectResponse
     * @throws GuzzleException
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        return parent::pay($transaction, $dataBag, $salesChannelContext);
    }
}
