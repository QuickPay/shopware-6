<?php declare(strict_types=1);

namespace Wexo\Quickpay\Controller;

use Monolog\Level;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Log\LogEntryCollection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Wexo\Quickpay\Service\QuickpayService;
use Wexo\Quickpay\Service\ShopwareStateService;
use Wexo\Quickpay\WexoQuickpay;

#[Route(defaults: ['_routeScope' => ['api']])]
class QuickpayRecurringController extends AbstractController
{
    /**
     * @param EntityRepository<LogEntryCollection> $logEntryRepository
     * @param EntityRepository<OrderCollection>    $orderRepository
     */
    public function __construct(
        protected EntityRepository $logEntryRepository,
        protected EntityRepository $orderRepository,
        protected QuickpayService $quickpayService,
        protected ShopwareStateService $shopwareStateService
    ) {
    }

    // phpcs:ignore
    #[Route(
        path: 'api/wexo/quickpay/recurring-callback',
        name: 'api.wexo.quickpay.recurring',
        defaults: ['auth_required' => false, 'csrf_protected' => false],
        methods: ['POST', 'GET']
    )]
    public function callback(Request $request, Context $context): JsonResponse|RedirectResponse
    {
        try {
            $this->logEntryRepository->create(
                [
                    [
                        'message' => 'quickpay.recurring.payment.callback',
                        'context' => [
                            'contentType' => $request->getContentTypeFormat(),
                            'content'     => $request->getContent()
                        ],
                        'level'   => Level::Info->value,
                        'channel' => 'quickpay'
                    ]
                ],
                $context
            );
        } catch (\Exception $e) {
            // do nothing
        }

        $response = json_decode((string) $request->getContent(), true);
        if (! isset($response['order_id'])) {
            return new JsonResponse([], Response::HTTP_BAD_REQUEST);
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderNumber', $response['order_id']));
        $criteria->addAssociation('transactions.stateMachineState');
        $criteria->addAssociation('stateMachineState');

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search($criteria, $context)->first();
        if ($order === null) {
            return new JsonResponse([], Response::HTTP_BAD_REQUEST);
        }

        $states = [
            OrderTransactionStates::STATE_AUTHORIZED,
            OrderTransactionStates::STATE_OPEN,
            OrderTransactionStates::STATE_FAILED,
            OrderTransactionStates::STATE_CANCELLED
        ];

        /** @var OrderTransactionEntity|null $transaction */
        $transaction = null;
        $transactions = $order->getTransactions();

        if ($transactions instanceof OrderTransactionCollection) {
            foreach ($states as $state) {
                $transaction = $transactions->filterByState($state)->first();
                if ($transaction instanceof OrderTransactionEntity) {
                    break;
                }
            }
        }

        if (!$transaction instanceof OrderTransactionEntity) {
            return new JsonResponse(['error' => 'No valid transaction found'], Response::HTTP_BAD_REQUEST);
        }

        $submittedChecksum = $request->server->get('HTTP_QUICKPAY_CHECKSUM_SHA256') ?? null;

        $valid = $this->quickpayService->checkPrivateKey(
            $order->getSalesChannelId(),
            $request->getContent(),
            $submittedChecksum
        );

        if (! $valid) {
            return new JsonResponse([], Response::HTTP_BAD_REQUEST);
        }

        $customFields = $order->getCustomFields();
        if (!is_array($customFields)) {
            $customFields = [];
        }

        $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD] = $request->getContent();
        $this->quickpayService->setOrderCustomFields($order->getId(), $customFields, $context);

        $paymentState = $transaction->getStateMachineState()?->getTechnicalName() ?? '';
        $orderState = $order->getStateMachineState()?->getTechnicalName() ?? '';

        $client = $this->quickpayService->getClient($order->getSalesChannelId());

        $customFields = $order->getCustomFields();
        $quickpayPaymentId = $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD]['id'] ?? null;
        
        if ($quickpayPaymentId === null || $quickpayPaymentId === '') {
            return new JsonResponse(['error' => 'No payment ID found'], Response::HTTP_BAD_REQUEST);
        }
        
        try {
            $paymentResponse = $client->request(
                'GET',
                'payments/' . $quickpayPaymentId
            )->getBody();
            
            $paymentResponseData = json_decode($paymentResponse->getContents(), true);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Payment lookup failed'], Response::HTTP_BAD_REQUEST);
        }

        if ((bool)($response['accepted'] ?? false) === true && is_array($paymentResponseData)) {
            try {
                if ($paymentResponseData['state'] === 'processed') {
                    $this->shopwareStateService->success(
                        $transaction->getId(),
                        $order->getId(),
                        $paymentState,
                        $orderState,
                        $context
                    );
                } elseif ($paymentResponseData['state'] === 'rejected') {
                    $this->shopwareStateService->cancel(
                        $transaction->getId(),
                        $order->getId(),
                        $paymentState,
                        $orderState,
                        $context
                    );
                }
            } catch (\Exception $e) {
                $message = 'Could not update order state authorized ';
                $this->logError($message, $order, $request, $context, $e);
            }
        } else {
            // status codes for rejected/aborted transactions, where we'll then cancel the order in Shopware.
            foreach ($response['operations'] as $operation) {
                if ($operation['type'] === 'authorize' &&
                    in_array($operation['qp_status_code'], ['40000', '40001', '40002', '40003', '50000', '50300'], true)
                ) {
                    try {
                        $this->shopwareStateService->cancel(
                            $transaction->getId(),
                            $order->getId(),
                            $paymentState,
                            $orderState,
                            $context
                        );
                    } catch (\Exception $e) {
                        $message = 'Could not update order state to cancel';
                        $this->logError($message, $order, $request, $context, $e);
                    }
                }
            }
        }

        return new JsonResponse([], Response::HTTP_OK);
    }

    public function logError(
        String $message,
        OrderEntity $order,
        Request $request,
        Context $context,
        \Exception $e
    ): void {
        $this->logEntryRepository->create(
            [
                [
                    'message' => $message,
                    'context' => [
                        'error' => $e->getMessage(),
                        'errorMessage'=> $e->getTraceAsString(),
                        'orderId' => $order->getId(),
                        'contentType' => $request->getContentTypeFormat(),
                        'content'     => $request->getContent()
                    ],
                    'level'   => Level::Critical->value,
                    'channel' => 'quickpay'
                ]
            ],
            $context
        );
    }
}
