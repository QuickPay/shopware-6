<?php declare(strict_types=1);

namespace Wexo\Quickpay\Controller;

use GuzzleHttp\Client;
use Shopware\Core\Checkout\Payment\PaymentService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Wexo\Quickpay\Service\QuickpayPayment;

/**
 * @RouteScope(scopes={"storefront"})
 */
class QuickpayStorefrontController
{
    /**
     * @var QuickpayPayment
     */
    protected $paymentService;

    /**
     * QuickpayApiController constructor.
     * @param PaymentService $paymentService
     */
    public function __construct(
        PaymentService $paymentService
    ) {
        $this->paymentService = $paymentService;
    }

    /**
     * @Route(
     *     path="/payment/quickpay-finalize-transaction",
     *     methods={"POST", "GET"},
     *     defaults={"auth_required"=false, "csrf_protected"=false}
     * )
     * @param Request $dataBag
     * @param SalesChannelContext $context
     * @return JsonResponse
     */
    public function quickpayFinalizeTransaction(Request $request, SalesChannelContext $context): JsonResponse
    {
        $data = [];
        $paymentToken = $request->get('_sw_payment_token');

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

        if ($data) {
            $this->paymentService->paymentLogger(
                'quickpay_finalize_transaction_error',
                [
                    $data
                ]
            );
        }

        return new JsonResponse($data, !empty($data) ? Response::HTTP_BAD_REQUEST : Response::HTTP_OK);
    }
}
