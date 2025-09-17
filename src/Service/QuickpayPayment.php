<?php declare(strict_types=1);

namespace Wexo\Quickpay\Service;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Level;
use Monolog\Logger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Wexo\Quickpay\Helper\ServiceHelper;
use Wexo\Quickpay\ServiceInterface\QuickpayInterface;
use Wexo\Quickpay\WexoQuickpay;

class QuickpayPayment extends AbstractPaymentHandler
{
    public static string $quickpayName = 'creditcard';
    protected QuickpayInterface $currentService;

    /**
     * @param EntityRepository<OrderTransactionCollection> $orderTransactionRepository
     * @param QuickpayInterface $paymentService
     * @param QuickpayInterface $subscriptionService
     * @param ShopwareStateService $shopwareStateService
     */
    public function __construct(
        private readonly EntityRepository $orderTransactionRepository,
        protected QuickpayInterface $paymentService,
        protected QuickpayInterface $subscriptionService,
        protected QuickpayService $quickpayService,
        protected ShopwareStateService $shopwareStateService
    ) {
    }

    private function loadTransaction(string $orderTransactionId, Context $context): OrderTransactionEntity
    {
        $criteria = new Criteria([$orderTransactionId])
            ->addAssociation('order')
            ->addAssociation('order.stateMachineState')
            ->addAssociation('paymentMethod')
            ->addAssociation('stateMachineState');

        $tx = $this->orderTransactionRepository->search($criteria, $context)->first();
        if ($tx === null) {
            throw PaymentException::invalidTransaction($orderTransactionId);
        }

        return $tx;
    }

    /**
     * @param Request $request
     * @param PaymentTransactionStruct $transaction
     * @param Context $context
     * @param Struct|null $validateStruct
     * @return RedirectResponse
     */
    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): RedirectResponse {
            $extraParams = $request->request->all('extraParams');
            $orderTransactionId = $transaction->getOrderTransactionId();
            $tx    = $this->loadTransaction($orderTransactionId, $context);
            $order = $tx->getOrder();
            if ($order === null) {
                throw new \Exception('Order not found for transaction: ' . $orderTransactionId);
            }
            $this->setCurrentService($order);

            $customFields = $order->getCustomFields() ?? [];
        try {
            if (! isset($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD])) {
                $this->currentService->create($transaction, $context);
            }
            $link = $this->currentService->getLink($transaction, $context, $extraParams);
        } catch (Exception $e) {
            $this->currentService->paymentLogger(
                WexoQuickpay::ORDER_CREATE_ERROR,
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'errorType' => $e::class
                ]
            );

            throw PaymentException::asyncProcessInterrupted(
                $orderTransactionId,
                'An error occurred during the communication with external payment gateway' . PHP_EOL .
                $e->getMessage()
            );
        }
        // Redirect to external gateway
        return new RedirectResponse($link);
    }

    /**
     * Finalize can handle both payments and subscription callbacks as the data required for a success scenario
     * is the same.
     *
     * @param PaymentTransactionStruct $transaction
     * @param Request $request
     * @param Context $context
     * @throws Exception|GuzzleException
     */
    public function finalize(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        $content = $request->getContent();
        $transactionId = $transaction->getOrderTransactionId();
        $tx = $this->loadTransaction($transactionId, $context);
        $order = $tx->getOrder();

        if ($order === null) {
            throw new \RuntimeException('Transaction has no associated Order.');
        }

        $status = $request->get('status');
        if ($status === "cancel") {
            throw PaymentException::customerCanceled(
                $transactionId,
                'Customer canceled the payment on the payment page'
            );
        } elseif ($content !== '') {
            $response = json_decode($content, true);

            $this->setCurrentService($order);

            $this->quickpayService->paymentLogger(
                'quickpay_callback_data_response',
                [
                    'orderId' => $order->getId(),
                    'data'    => $response
                ]
            );

            // Validate the checksum being sent from QuickPay
            $submittedChecksum = $request->server->get('HTTP_QUICKPAY_CHECKSUM_SHA256') ?? '';
            $valid = $this->quickpayService->checkPrivateKey($order->getSalesChannelId(), $content, $submittedChecksum);
            if (! $valid) {
                throw new \Exception('Checksum check failed for orderId: ' . $order->getId());
            }

            $orderTransaction = $tx;
            $orderTransactionStateMachineState = $orderTransaction->getStateMachineState();
            $orderStateMachineState = $order->getStateMachineState();

            if ($orderTransactionStateMachineState === null || $orderStateMachineState === null) {
                throw new \Exception('State machine state not loaded for transaction ID: ' . $transactionId);
            }

            $paymentState = $orderTransactionStateMachineState->getTechnicalName();
            $orderState = $orderStateMachineState->getTechnicalName();

            $accepted = $response['accepted'] ?? false;
            if ($accepted === true) {
                $paymentHandler = $tx->getPaymentMethod()?->getHandlerIdentifier();

                if ($paymentHandler === null) {
                    return;
                }

                if ($paymentHandler === SwishPayment::class) {
                    // Since Swish is a banktransfer, capture happens at the same time as Authorized.
                    // So we set payment status to Paid instead of Authorized.
                    $this->shopwareStateService->paid($transactionId, $order->getId(), $paymentState, $orderState);
                } else {
                    // if the payment has been accepted in quickpay, we'll set the Shopware payment status to authorized
                    // and the order status to in progress.
                    $this->shopwareStateService->success($transactionId, $order->getId(), $paymentState, $orderState);
                }

                // if it's a subscription, we'll create a recurring payment, that then still needs to be captured.
                if ($this->currentService instanceof SubscriptionQuickpayService) {
                    $this->currentService->recurring($order->getId());
                }
            } elseif (isset($response['operations']) && $paymentState !== OrderTransactionStates::STATE_AUTHORIZED) {
                $cancel = false;
                // status codes for rejected/aborted transactions, where we'll then cancel the order in Shopware.
                foreach ($response['operations'] as $operation) {
                    if ($operation['type'] === 'authorize' &&
                        in_array($operation['qp_status_code'], ['40000', '40001', '40002', '40003', '50000', '50300'], true)
                    ) {
                        $cancel = true;
                    }
                }

                if ($cancel === true) {
                    $this->shopwareStateService->cancel($transactionId, $order->getId(), $paymentState, $orderState);
                }
            }
        }
    }

    private function setCurrentService(OrderEntity $order): void
    {
        $this->currentService = $this->paymentService;

        $subscription = ServiceHelper::isOrderSubscription($order);
        if ($subscription) {
            $this->currentService = $this->subscriptionService;
        }
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        // TODO: Implement supports() method.
        return false;
    }
}
