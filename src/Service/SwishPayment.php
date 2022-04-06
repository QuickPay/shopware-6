<?php declare(strict_types=1);

namespace Wexo\Quickpay\Service;

use GuzzleHttp\Exception\GuzzleException;
use Shopware\Core\Checkout\Cart\CartPersisterInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Shopware\Core\System\StateMachine\StateMachineRegistry;

/**
 * Class SwishPayment
 * @package Wexo\Quickpay\Service
 */
class SwishPayment extends QuickpayPayment
{
    protected static string $quickpayName = 'swish';

    /**
     * SwishPayment constructor.
     * @param SystemConfigService $systemConfigService
     * @param EntityRepositoryInterface $logEntryRepository
     * @param EntityRepositoryInterface $orderRepository
     * @param EntityRepositoryInterface $languageRepository
     * @param OrderTransactionStateHandler $transactionStateHandler
     * @param CartPersisterInterface $cartPersister
     * @param StateMachineRegistry $stateMachineRegistry
     */
    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepositoryInterface $logEntryRepository,
        EntityRepositoryInterface $orderRepository,
        EntityRepositoryInterface $languageRepository,
        OrderTransactionStateHandler $transactionStateHandler,
        CartPersisterInterface $cartPersister,
        StateMachineRegistry $stateMachineRegistry
    ) {
        parent::__construct(
            $systemConfigService,
            $logEntryRepository,
            $orderRepository,
            $languageRepository,
            $transactionStateHandler,
            $cartPersister,
            $stateMachineRegistry
        );
    }

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
