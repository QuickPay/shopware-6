<?php declare(strict_types=1);

namespace Wexo\Quickpay\Controller\Api;

use GuzzleHttp\Client;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Wexo\Quickpay\Service\QuickpayPayment;

/**
 * @RouteScope(scopes={"administration"})
 */
class ApiTestController
{
    /**
     * @var QuickpayPayment
     */
    protected $quickpayPayment;

    /**
     * @var Client
     */
    protected $client;

    /**
     * ApiTestController constructor.
     * @param QuickpayPayment $quickpayPayment
     */
    public function __construct(
        QuickpayPayment $quickpayPayment
    ) {
        $this->quickpayPayment = $quickpayPayment;

        $this->client = new Client();
    }

    /**
     * @Route(path="/api/v{version}/_action/quickpay-api-test/verify")
     * @param RequestDataBag $dataBag
     * @return JsonResponse
     */
    public function check(RequestDataBag $dataBag): JsonResponse
    {
        $config = [
            'quickpayApiKey' => $dataBag->get('quickpayApiKey'),
            'mobilepayId' => $dataBag->get('mobilepayId')
        ];

        if ($this->quickpayPayment->isConfigValid($config)) {
            return new JsonResponse(['isValid' => true]);
        }

        return new JsonResponse(['isValid' => false]);
    }
}
