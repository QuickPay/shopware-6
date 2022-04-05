<?php declare(strict_types=1);

namespace Wexo\Quickpay\Service;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Wexo\Quickpay\Helper\ServiceHelper;
use Wexo\Quickpay\ServiceInterface\QuickpayInterface;
use Wexo\Quickpay\WexoQuickpay;

/**
 * Class QuickpayPayment
 * @package Wexo\Quickpay\Service
 */
class QuickpayPayment implements AsynchronousPaymentHandlerInterface
{

    protected QuickpayInterface $paymentService;
    protected QuickpayInterface $subscriptionService;
    protected QuickpayInterface $currentService;
    protected ShopwareStateService $shopwareStateService;

    /**
     * @param QuickpayInterface $paymentService
     * @param QuickpayInterface $subscriptionService
     * @param ShopwareStateService $shopwareStateService
     */
    public function __construct(
        QuickpayInterface $paymentService,
        QuickpayInterface $subscriptionService,
        ShopwareStateService $shopwareStateService,
    ) {
        $this->paymentService = $paymentService;
        $this->subscriptionService = $subscriptionService;
        $this->shopwareStateService = $shopwareStateService;
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
        // Method that sends the return URL to the external gateway and gets a redirect URL back
        try {
            $order = $transaction->getOrder();

            $this->setCurrentService($order);

            $customFields = $order->getCustomFields() ?? [];
            if (! isset($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD])) {
                $this->currentService->create($transaction, $salesChannelContext);
            }
            $link = $this->currentService->getLink($transaction, $salesChannelContext);
        } catch (Exception $e) {
            $this->currentService->paymentLogger(
                WexoQuickpay::ORDER_CREATE_ERROR,
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'errorType' => get_class($e)
                ]
            );

            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
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
     * @param AsyncPaymentTransactionStruct $transaction
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @throws Exception
     */
    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        $content = $request->getContent();
        $transactionId = $transaction->getOrderTransaction()->getId();

        $status = $request->get('status');
        if ($status === "cancel") {
            throw new CustomerCanceledAsyncPaymentException(
                $transactionId,
                'Customer canceled the payment on the payment page'
            );
        } elseif ($content) {
            $response = json_decode($content, true);

            $order = $transaction->getOrder();
            $this->setCurrentService($order);

            $this->currentService->paymentLogger(
                'quickpay_callback_data_response',
                [
                    'orderId' => $order->getId(),
                    'data'    => $response
                ],
                Logger::INFO
            );

            // Validate the checksum being sent from QuickPay
            $submittedChecksum = $request->server->get('HTTP_QUICKPAY_CHECKSUM_SHA256') ?? '';
            $valid = $this->paymentService->checkPrivateKey($order->getSalesChannelId(), $content, $submittedChecksum);
            if (! $valid) {
                throw new \Exception('Checksum check failed for orderId: ' . $order->getId());
            }

            $paymentState = $transaction->getOrderTransaction()->getStateMachineState()->getTechnicalName();
            $orderState = $transaction->getOrder()->getStateMachineState()->getTechnicalName();

            $accepted = $response['accepted'] ?? false;
            if ($accepted) {
                // if the payment has been accepted in quickpay, we'll set the Shopware payment status to authorized
                // and the order status to in progress.
                $this->shopwareStateService->success($transactionId, $order->getId(), $paymentState, $orderState);

                // if it's a subscription, we'll create a recurring payment, that then still needs to be captured.
                if ($this->currentService instanceof SubscriptionQuickpayService) {
                    $this->currentService->recurring($order->getId());
                }
            } elseif (isset($response['operations']) && $paymentState !== OrderTransactionStates::STATE_AUTHORIZED) {
                $cancel = false;
                // status codes for rejected/aborted transactions, where we'll then cancel the order in Shopware.
                foreach ($response['operations'] as $operation) {
                    if ($operation['type'] === 'authorize' &&
                        in_array($operation['qp_status_code'], ['40000', '40001', '40002', '40003', '50000', '50300'])
                    ) {
                        $cancel = true;
                    }
                }

                if ($cancel) {
                    $this->shopwareStateService->cancel($transactionId, $order->getId(), $paymentState, $orderState);
                }
            }
        }
    }

    /**
     * @param OrderEntity $order
     * @return void
     */
    private function setCurrentService(OrderEntity $order): void
    {
        $this->currentService = $this->paymentService;

        $subscription = ServiceHelper::isOrderSubscription($order);
        if ($subscription) {
            $this->currentService = $this->subscriptionService;
        }
    }
}
