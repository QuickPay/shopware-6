<?php


namespace QuickPay\Controller;

use Exception;
use QuickPay\Service\PaymentService;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;

/**
 * @RouteScope(scopes={"api"})
 */
class AdministrationController
{
    /**
     * @var PaymentService
     */
    private $paymentService;
    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }
    
    /**
     * @Route(
     *     "/api/v{version}/_action/quickpay/capture",
     *     name="api.action.quickpay.capture_old",
     *     methods={"POST"}
    
     * )
     */
    public function captureOld(Request $request): JsonResponse
    {
        return $this->capture($request);
    }
    
    /**
     * @Route(
     *     "/api/_action/quickpay/capture",
     *     name="api.action.quickpay.capture",
     *     methods={"POST"}
    
     * )
     */
    public function capture(Request $request): JsonResponse
    {
        try {
            $body = $request->getContent();
            $data = json_decode($body);
            if (!isset($data->id) || !isset($data->amount))
                throw new Exception('Invalid request body');
            $this->paymentService->requestCapture($data->id, $data->amount, Context::createDefaultContext());
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    
        return new JsonResponse(['success' => true], 200);
    }
    
    /**
     * @Route(
     *     "/api/v{version}/_action/quickpay/cancel",
     *     name="api.action.quickpay.cancel_old",
     *     methods={"POST"}
    
     * )
     */
    public function cancelOld(Request $request): JsonResponse
    {
        return $this->cancel($request);
    }
    
    /**
     * @Route(
     *     "/api/_action/quickpay/cancel",
     *     name="api.action.quickpay.cancel",
     *     methods={"POST"}
    
     * )
     */
    public function cancel(Request $request): JsonResponse
    {
        try {
            $body = $request->getContent();
            $data = json_decode($body);
            if (!isset($data->id))
                throw new Exception('Invalid request body');
            $this->paymentService->requestCancel($data->id, Context::createDefaultContext());
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    
        return new JsonResponse(['success' => true], 200);
    }
    
    /**
     * @Route(
     *     "/api/v{version}/_action/quickpay/refund",
     *     name="api.action.quickpay.refund_old",
     *     methods={"POST"}
    
     * )
     */
    public function refundOld(Request $request): JsonResponse
    {
        return $this->refund($request);
    }
    
    /**
     * @Route(
     *     "/api/_action/quickpay/refund",
     *     name="api.action.quickpay.refund",
     *     methods={"POST"}
    
     * )
     */
    public function refund(Request $request): JsonResponse
    {
        try {
            $body = $request->getContent();
            $data = json_decode($body);
            if (!isset($data->id) || !isset($data->amount))
                throw new Exception('Invalid request body');
            $this->paymentService->requestRefund($data->id, $data->amount, Context::createDefaultContext());
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    
        return new JsonResponse(['success' => true], 200);
    }
    
    /**
     * @Route(
     *     "/api/v{version}/_action/quickpay/refresh",
     *     name="api.action.quickpay.refresh_old",
     *     methods={"POST"}
    
     * )
     */
    public function refreshOld(Request $request): JsonResponse
    {
        return $this->refresh($request);
    }
    
    /**
     * @Route(
     *     "/api/_action/quickpay/refresh",
     *     name="api.action.quickpay.refresh",
     *     methods={"POST"}
    
     * )
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            $body = $request->getContent();
            $data = json_decode($body);
            if (!isset($data->id))
                throw new Exception('Invalid request body');
            $context = Context::createDefaultContext();
            $transactionId = $this->paymentService->findTransactionId($data->id, $context);
            $this->paymentService->updateTransaction($transactionId, $context);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    
        return new JsonResponse(['success' => true], 200);
    }
}
