<?php declare(strict_types=1);

namespace Wexo\Quickpay\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Level;
use Psr\Http\Message\ResponseInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\HttpException;
use Shopware\Core\Framework\Log\LogEntryCollection;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\Framework\Util\FloatComparator;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Wexo\Quickpay\Content\RefundExceptions;
use Wexo\Quickpay\WexoQuickpay;

class RefundService
{
    /**
     * @var array<string, mixed>|null
     */
    protected ?array $response = null {
        get {
            return $this->response;
        }
    }

    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     * @param PluginIdProvider $pluginIdProvider
     * @param SystemConfigService $configService
     * @param EntityRepository<LogEntryCollection> $logEntryRepository
     */
    public function __construct(
        protected EntityRepository $orderRepository,
        protected PluginIdProvider $pluginIdProvider,
        protected SystemConfigService $configService,
        protected EntityRepository $logEntryRepository
    ) {
    }

    /**
     * @throws GuzzleException
     * @throws HttpException
     * TODO: Extend @see RefundPaymentHandlerInterface
     */
    public function refund(
        string $orderId,
        float $amount,
        Context $context
    ): bool {
        if (!$amount) {
            throw RefundExceptions::invalidAmount($amount);
        }

        if ($amount <= 0.0) {
            throw RefundExceptions::invalidAmount($amount);
        }

        $pluginId = $this->pluginIdProvider->getPluginIdByBaseClass(WexoQuickpay::class, $context);

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search(
            new Criteria([$orderId])
                ->addFilter(
                    new EqualsAnyFilter('transactions.stateMachineState.technicalName', [
                        OrderTransactionStates::STATE_PAID,
                        OrderTransactionStates::STATE_PARTIALLY_PAID,
                        OrderTransactionStates::STATE_AUTHORIZED,
                        OrderTransactionStates::STATE_PARTIALLY_REFUNDED,
                    ])
                )
                ->addFilter(new EqualsFilter('transactions.paymentMethod.pluginId', $pluginId))
                ->addAssociation('transactions'),
            $context
        )->get($orderId);

        if (!$order || !isset($order->getCustomFields()[WexoQuickpay::QUICKPAY_RESPONSE_FIELD])) {
            throw RefundExceptions::orderNotFound($order);
        }

        $response = json_decode(
            (string)$order->getCustomFields()[WexoQuickpay::QUICKPAY_RESPONSE_FIELD],
            true
        );

        if (!$response) {
            throw RefundExceptions::invalidResponse();
        }

        if (!isset($response['id']) ||
            ($response['order_id'] ?? null) !== $order->getOrderNumber()
        ) {
            throw RefundExceptions::invalidOrder($orderId);
        }

        $client = new Client([
            'base_uri' => 'https://api.quickpay.net/',
            'headers' => [
                'Accept' => '*/*',
                'Accept-Encoding' => 'gzip, deflate',
                'Accept-Version' => 'v10',
                'Connection' => 'keep-alive',
                'Host' => 'api.quickpay.net',
                'cache-control' => 'no-cache'
            ],
            'auth' => [
                '',
                $this->configService->get(
                    'WexoQuickpay.config.quickpayApiKey',
                    $order->getSalesChannelId()
                )
            ],
        ]);

        $clientResponse = $client->get('payments/' . $response['id']);
        $this->verifyResponse($clientResponse, $orderId, $context);

        $currentBalance = (float)($this->response['balance'] ?? 0);
        if (FloatComparator::greaterThan($amount * 100, $currentBalance)) {
            throw RefundExceptions::refundAmountTooLarge($amount, $currentBalance / 100);
        }

        $refundResponse = $client->post(
            uri: 'payments/' . $response['id'] . '/refund?synchronized=true',
            options: [
                'form_params' => [
                    'amount' => $amount * 100,
                ]
            ]
        );
        $this->verifyResponse($refundResponse, $orderId, $context);
        if (in_array($refundResponse->getStatusCode(), [200, 202], true)) {
            $source = $context->getSource();
            if ($source instanceof AdminApiSource && $source->getUserId()) {
                $this->logEntryRepository->create([
                    [
                        'message' => 'quickpay.order.refund.info',
                        'context' => [
                            'id' => $response['id'],
                            'orderId' => $orderId,
                            'amount' => $amount,
                            'userId' => $source->getUserId(),
                        ],
                        'level' => Level::Info->value,
                        'channel' => WexoQuickpay::LOG_CHANNEL
                    ]
                ], $context);
            }

            if (isset($this->response['operations']) && is_array($this->response['operations'])) {
                $refunds = array_filter(
                    $this->response['operations'],
                    static fn($operation) => $operation['type'] === 'refund'
                );

                $currentRefund = end($refunds);
                if (($currentRefund['qp_status_msg'] ?? null) === 'Approved' ||
                    ($currentRefund['aq_status_msg'] ?? null) === 'Approved'
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function verifyResponse(ResponseInterface $response, string $orderId, Context $context): void
    {
        $content = $response->getBody()->getContents();
        if (in_array($response->getStatusCode(), [200, 202], true) && $content) {
            $this->orderRepository->upsert([
                [
                    'id' => $orderId,
                    'customFields' => [
                        WexoQuickpay::QUICKPAY_RESPONSE_FIELD => $content,
                    ]
                ]
            ], $context);
        }

        $this->response = json_decode($content, true);
    }

}
