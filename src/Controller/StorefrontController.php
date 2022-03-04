<?php


namespace QuickPay\Controller;

use Exception;
use QuickPay\Service\PaymentService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;

/**
 * @RouteScope(scopes={"storefront"})
 */
class StorefrontController
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
     * @Route("/quickpay/callback", name="quickpay.callback", defaults={"csrf_protected"=false}, options={"seo"="false"}, methods={"GET", "POST"})
     */
    public function callback(Request $request, SalesChannelContext $context): Response
    {
        try {
            $body = $request->getContent();
            $paymentData = json_decode($body);
            $paymentId = $paymentData->id ?? null;
            $transactionId = $paymentData->variables->transaction_id ?? null;
            if (!$paymentId || !$transactionId)
                throw new Exception('Invalid request body');

            $this->paymentService->validateChecksum($paymentId, $request, $context);
            $this->paymentService->updateTransaction($transactionId, $context->getContext(), $paymentData);
        } catch (Exception $e) {
            return new Response($e->getMessage(), 400);
        }
    
        return new Response('OK', 200);
    }
    
    /**
     * @Route("/quickpay/phpvr", name="quickpay.callback.phpvr", defaults={"csrf_protected"=false}, options={"seo"="false"}, methods={"GET", "POST"})
     */
    public function phpvr(Request $request, SalesChannelContext $context): Response
    {
        phpinfo();
        return new Response('OK', 200);
    }
}
