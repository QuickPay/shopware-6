<?php declare(strict_types=1);

namespace Wexo\Quickpay\Controller;

use Monolog\Logger;
use Shopware\Core\Checkout\Cart\CartPersisterInterface;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenFactoryInterfaceV2;
use Shopware\Core\Checkout\Payment\PaymentService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Wexo\Quickpay\WexoQuickpay;

/**
 * @RouteScope(scopes={"storefront"})
 */
class QuickpayStorefrontController
{
    protected EntityRepositoryInterface $logEntryRepository;
    protected PaymentService $paymentService;
    protected TokenFactoryInterfaceV2 $tokenFactory;
    protected CartPersisterInterface $cartPersister;

    /**
     * QuickpayApiController constructor.
     *
     * @param EntityRepositoryInterface $logEntryRepository
     * @param PaymentService $paymentService
     */
    public function __construct(
        EntityRepositoryInterface $logEntryRepository,
        PaymentService $paymentService,
        TokenFactoryInterfaceV2 $tokenFactory,
        CartPersisterInterface $cartPersister
    ) {
        $this->logEntryRepository = $logEntryRepository;
        $this->paymentService = $paymentService;
        $this->tokenFactory = $tokenFactory;
        $this->cartPersister = $cartPersister;
    }

    /**
     * @Route(
     *     path="/payment/quickpay-finalize-transaction",
     *     methods={"POST", "GET"},
     *     defaults={"auth_required"=false, "csrf_protected"=false}
     * )
     * @param Request $request
     * @param SalesChannelContext $context
     *
     * @return JsonResponse|RedirectResponse
     */
    public function quickpayFinalizeTransaction(Request $request, SalesChannelContext $context)
    {
        $finalizeAllowed = true;
        $data = [];
        $paymentToken = $request->get('_sw_payment_token');
        $status = $request->query->get('status');
        $allowedStatuses = [30100, 30101,40000, 40001, 50000, 50300];

        $operations = $request->get('operations');
        if (!empty($operations)) {
            $operation = end($operations);
            /*
             * 30100 and 30101 indicate errors based on rejected 3D Secure
             * https://learn.quickpay.net/tech-talk/appendixes/errors/
             */
            if (!isset($operation->qp_status_code) || in_array($operation->qp_status_code, $allowedStatuses)) {
                $finalizeAllowed = false;
            }
        }


        /* Delete cart when either customer or quickpay reaches this page.
         * This just runs executeStatement, which just returns number of rows affected,
         * so multiple runs on runs non existing cart does not throw an error */
        if ($status === "accepted") {
            $this->cartPersister->delete($context->getToken(), $context);
        }

        if (in_array($status, ['accepted', 'cancel'])) {
            $token = $this->tokenFactory->parseToken($paymentToken);
            $url = ($status == 'accepted' ? $token->getFinishUrl() : $token->getErrorUrl());
            return new RedirectResponse($url);
        } else {
            sleep(10);
        }
        $paymentToken = $request->get('_sw_payment_token');

        if ($finalizeAllowed) {
            try {
                $result = $this->paymentService->finalizeTransaction(
                    $paymentToken,
                    $request,
                    $context
                );

                $exception = $result->getException();
                if ($exception) {
                    $data = [
                        'error' => $exception->getMessage()
                    ];
                }
            } catch (\Exception $exception) {
                $data = [
                    'error' => $exception->getMessage()
                ];
            }
        }

        if ($data) {
            if ($request->getContent()) {
                $data['content'] = json_decode($request->getContent(), true);
            }

            $this->logEntryRepository->create(
                [
                    [
                        'message' => 'quickpay_finalize_transaction_error',
                        'context' => $data,
                        'level' => Logger::ERROR,
                        'channel' => WexoQuickpay::LOG_CHANNEL
                    ]
                ],
                Context::createDefaultContext()
            );
        }

        return new JsonResponse($data, !empty($data) ? Response::HTTP_BAD_REQUEST : Response::HTTP_OK);
    }
}
