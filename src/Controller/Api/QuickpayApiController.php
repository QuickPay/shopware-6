<?php declare(strict_types=1);

namespace Wexo\Quickpay\Controller\Api;

use GuzzleHttp\Client;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Wexo\Quickpay\Service\QuickpayPayment;

/**
 * @RouteScope(scopes={"administration"})
 */
class QuickpayApiController
{
    /**
     * @var QuickpayPayment
     */
    protected $quickpayPayment;

    /**
     * QuickpayApiController constructor.
     * @param QuickpayPayment $quickpayPayment
     */
    public function __construct(
        QuickpayPayment $quickpayPayment
    ) {
        $this->quickpayPayment = $quickpayPayment;
    }

    /**
     * @Route(path="/api/_action/quickpay-api/verify")
     * @param RequestDataBag $dataBag
     * @return JsonResponse
     */
    public function check(RequestDataBag $dataBag): JsonResponse
    {
        $config = [
            'quickpayApiKey' => $dataBag->get('WexoQuickpay.config.quickpayApiKey')
        ];

        if ($this->quickpayPayment->isConfigValid($config)) {
            return new JsonResponse(['isValid' => true]);
        }

        return new JsonResponse(['isValid' => false]);
    }

    /**
     * @Route(
     *     path="/api/_action/quickpay-api/capture",
     *     methods={"POST"},
     *     defaults={"auth_required"=false}
     * )
     * @param RequestDataBag $dataBag
     * @return JsonResponse
     */
    public function capture(RequestDataBag $dataBag): JsonResponse
    {
        $amount = $dataBag->get('amount');
        $orderId = $dataBag->get('orderId');

        $success = $this->quickpayPayment->capturePayment($orderId, $amount);

        return new JsonResponse([
            'success' => $success
        ]);
    }

    /**
     * @Route(
     *     path="/api/_action/quickpay-api/update",
     *     methods={"POST"},
     *     defaults={"auth_required"=false}
     * )
     * @param RequestDataBag $dataBag
     * @return JsonResponse
     */
    public function update(RequestDataBag $dataBag): JsonResponse
    {
        $orderId = $dataBag->get('orderId');

        $this->quickpayPayment->updateResponse($orderId);

        return new JsonResponse();
    }
}
