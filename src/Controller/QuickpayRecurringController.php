<?php declare(strict_types=1);

namespace Wexo\Quickpay\Controller;

use Monolog\Logger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Wexo\Quickpay\Service\QuickpayService;
use Wexo\Quickpay\Service\ShopwareStateService;
use Wexo\Quickpay\WexoQuickpay;

/**
 * @RouteScope(scopes={"api"})
 */
class QuickpayRecurringController extends AbstractController
{
    protected EntityRepositoryInterface $logEntryRepository;
    protected EntityRepositoryInterface $orderRepository;
    protected QuickpayService $quickpayService;
    protected ShopwareStateService $shopwareStateService;

    /**
     * @param EntityRepositoryInterface $logEntryRepository
     * @param EntityRepositoryInterface $orderRepository
     * @param QuickpayService $quickpayService
     * @param ShopwareStateService $shopwareStateService
     */
    public function __construct(
        EntityRepositoryInterface $logEntryRepository,
        EntityRepositoryInterface $orderRepository,
        QuickpayService $quickpayService,
        ShopwareStateService $shopwareStateService
    ) {
        $this->logEntryRepository = $logEntryRepository;
        $this->orderRepository = $orderRepository;
        $this->quickpayService = $quickpayService;
        $this->shopwareStateService = $shopwareStateService;
    }

    /**
     * @Route("api/wexo/quickpay/recurring-callback",
     *     name="api.wexo.quickpay.recurring",
     *     methods={"POST", "GET"},
     *     defaults={"auth_required"=false, "csrf_protected"=false}
     * )
     * @param Request $request
     *
     * @return JsonResponse|RedirectResponse
     */
    public function callback(Request $request)
    {
        try {
            $this->logEntryRepository->create(
                [
                    [
                        'message' => 'quickpay.recurring.payment.callback',
                        'context' => [
                            'contentType' => $request->getContentType(),
                            'content'     => $request->getContent()
                        ],
                        'level'   => Logger::INFO,
                        'channel' => 'quickpay'
                    ]
                ],
                Context::createDefaultContext()
            );
        } catch (\Exception $e) {
            // do nothing
        }

        $response = json_decode($request->getContent(), true);
        if (! isset($response['order_id'])) {
            return new JsonResponse([],Response::HTTP_BAD_REQUEST);
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderNumber', $response['order_id']));
        $criteria->addAssociation('transactions.stateMachineState');
        $criteria->addAssociation('stateMachineState');

        /** @var OrderEntity $order */
        $order = $this->orderRepository->search($criteria, Context::createDefaultContext())->first();
        if (! $order) {
            return new JsonResponse([],Response::HTTP_BAD_REQUEST);
        }

        $states = [
            OrderTransactionStates::STATE_AUTHORIZED,
            OrderTransactionStates::STATE_OPEN,
            OrderTransactionStates::STATE_FAILED,
            OrderTransactionStates::STATE_CANCELLED
        ];

        foreach ($states as $state) {
            $transaction = $order->getTransactions()->filterByState($state)->first();
            if ($transaction) {
                break;
            }
        }

        $submittedChecksum = $request->server->get('HTTP_QUICKPAY_CHECKSUM_SHA256') ?? null;

        $valid = $this->quickpayService->checkPrivateKey(
            $order->getSalesChannelId(),
            $request->getContent(),
            $submittedChecksum
        );

        if (! $valid) {
            return new JsonResponse([],Response::HTTP_BAD_REQUEST);
        }

        try {
            $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD] = $request->getContent();
            $this->quickpayService->setOrderCustomFields($order->getId(), $customFields);

            $paymentState = $transaction->getStateMachineState()->getTechnicalName();
            $orderState = $order->getStateMachineState()->getTechnicalName();

            $accepted = $response['accepted'] ?? false;
            if ($accepted) {
                $this->shopwareStateService->success(
                    $transaction->getId(),
                    $order->getId(),
                    $paymentState,
                    $orderState
                );
            } else {
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
                    $this->shopwareStateService->cancel(
                        $transaction->getId(),
                        $order->getId(),
                        $paymentState,
                        $orderState
                    );
                }
            }
        } catch (\Exception $e) {
            $this->logEntryRepository->create(
                [
                    [
                        'message' => 'quickpay.recurring.payment.error',
                        'context' => [
                            'error' => $e->getMessage(),
                            'errorMessage'=> $e->getTraceAsString(),
                            'orderId' => $order->getId(),
                            'contentType' => $request->getContentType(),
                            'content'     => $request->getContent()
                        ],
                        'level'   => Logger::CRITICAL,
                        'channel' => 'quickpay'
                    ]
                ],
                Context::createDefaultContext()
            );
        }

        return new JsonResponse([], Response::HTTP_OK);
    }
}
