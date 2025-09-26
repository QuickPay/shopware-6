<?php declare(strict_types=1);

namespace Wexo\Quickpay\Controller\Api;

use GuzzleHttp\Exception\GuzzleException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Wexo\Quickpay\Service\PaymentQuickpayService;

#[Route(defaults: ['_routeScope' => ['administration']])]
class QuickpayApiController
{
    public function __construct(protected PaymentQuickpayService $paymentQuickpayService)
    {
    }

    /**
     * @throws GuzzleException
     */
    #[Route(path: '/api/_action/quickpay-api/verify')]
    public function check(RequestDataBag $dataBag): JsonResponse
    {
        $config = [
            'quickpayApiKey' => $dataBag->get('WexoQuickpay.config.quickpayApiKey'),
            'quickpayPrivateKey' => $dataBag->get('WexoQuickpay.config.quickpayPrivateKey')
        ];

        if ($this->paymentQuickpayService->isConfigValid($config)) {
            return new JsonResponse(['isValid' => true]);
        }

        return new JsonResponse(['isValid' => false]);
    }

    /**
     * @throws GuzzleException
     */
    #[Route(path: '/api/_action/quickpay-api/capture', defaults: ['auth_required' => false], methods: ['POST'])]
    public function capture(RequestDataBag $dataBag, Context $context): JsonResponse
    {
        $amount = $dataBag->get('amount');
        $orderId = $dataBag->get('orderId');

        $success = $this->paymentQuickpayService->capture($orderId, $context, $amount);

        return new JsonResponse([
            'success' => $success
        ]);
    }

    /**
     * @throws GuzzleException
     */
    #[Route(path: '/api/_action/quickpay-api/update', defaults: ['auth_required' => false], methods: ['POST'])]
    public function update(RequestDataBag $dataBag, Context $context): JsonResponse
    {
        $orderId = $dataBag->get('orderId');

        $content = $this->paymentQuickpayService->updateResponse($orderId, $context);

        $response = new JsonResponse([]);
        $response->setContent($content);
        return $response;
    }
}
