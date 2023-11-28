<?php declare(strict_types=1);

namespace Wexo\Quickpay\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Level;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Wexo\Quickpay\WexoQuickpay;

class QuickpayService
{
    /** @var Client[] $apiClients */
    protected array $apiClients = [];

    public function __construct(
        protected SystemConfigService $systemConfigService,
        protected EntityRepository $logEntryRepository,
        protected EntityRepository $languageRepository,
        protected EntityRepository $orderRepository,
        protected OrderTransactionStateHandler $transactionStateHandler,
        protected StateMachineRegistry $stateMachineRegistry
    ) {
    }

    public function getClient(?string $salesChannelId): Client
    {
        //If no string is supplied to system config service, it uses '_global_' under the hood.
        if (!$salesChannelId) {
            $salesChannelId = '_global_';
        }

        if (!isset($this->apiClients[$salesChannelId])) {
            $apiKey = $this->systemConfigService->get('WexoQuickpay.config.quickpayApiKey', $salesChannelId);

            $this->apiClients[$salesChannelId] = new Client([
                'base_uri' => 'https://api.quickpay.net/',
                'headers' => [
                    "Accept" => "*/*",
                    "Accept-Encoding" => "gzip, deflate",
                    "Accept-Version" => "v10",
                    "Connection" => "keep-alive",
                    "Host" => "api.quickpay.net",
                    "cache-control" => "no-cache"
                ],
                'auth' => [
                    '',
                    $apiKey
                ],
            ]);
        }

        return $this->apiClients[$salesChannelId];
    }

    public function checkPrivateKey(
        string $salesChannelId,
        string $content,
        string $submittedChecksum
    ): bool {
        $key = $this->systemConfigService->get('WexoQuickpay.config.quickpayPrivateKey', $salesChannelId);

        $checksum = hash_hmac('sha256', $content, $key);

        return $checksum === $submittedChecksum;
    }

    /**
     * @throws GuzzleException
     */
    public function isConfigValid(array $config): bool
    {
        try {
            $response = $this->getClient(null)->request('GET', 'account/private-key', [
                'auth' => [
                    '',
                    $config['quickpayApiKey']
                ]
            ]);

            $pKey = json_decode($response->getBody()->getContents());
            if ($pKey->private_key == $config['quickpayPrivateKey']) {
                return $response->getStatusCode() === 200;
            }
            return false;
        } catch (\Exception $exception) {
            $this->paymentLogger($exception->getMessage(), $exception->getTrace());

            return false;
        }
    }

    public function paymentLogger(
        string $event,
        array $context,
        int $level = Level::Error->value
    ): void {
        $this->logEntryRepository->create(
            [
                [
                    'message' => $event,
                    'context' => $context,
                    'level' => $level,
                    'channel' => WexoQuickpay::LOG_CHANNEL
                ]
            ],
            Context::createDefaultContext()
        );
    }

    protected function getLanguage(string $languageId, Context $context): string
    {
        $criteria = new Criteria([$languageId]);
        $criteria->addAssociation('locale');
        $language = $this->languageRepository->search($criteria, $context)->first();

        // Map both norwegian locales to no
        $map = [
            'nb' => 'no',
            'nn' => 'no',
        ];

        $language = explode('-', (string) $language->getLocale()->getCode())[0];

        if (isset($map[$language])) {
            return $map[$language];
        }

        return $language;
    }

    /**
     * @throws GuzzleException
     */
    public function updateResponse(
        string $orderId,
        mixed $paymentId = null,
        ?SalesChannelContext $context = null
    ): ?string {
        $context = $context ? $context->getContext() : Context::createDefaultContext();

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search(new Criteria([$orderId]), $context)->get($orderId);
        if (! $order) {
            return null;
        }

        if (! $paymentId) {
            $customFields = $order->getCustomFields();
            if ($customFields && isset($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD])) {
                $data = json_decode((string) $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD]);

                if (property_exists($data, 'id')) {
                    $paymentId = $data->id;
                }
            }
        }

        if (! $paymentId) {
            return null;
        }

        try {
            $response = $this->getClient($order->getSalesChannelId())
                ->request('GET', 'payments/' . $paymentId);
            $content = $response->getBody()->getContents();
            if ($response->getStatusCode() === 200 && $content) {
                $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD] = $content;
                $this->setOrderCustomFields($orderId, $customFields);

                return $content;
            }
        } catch (\Exception $e) {
            $this->paymentLogger(
                WexoQuickpay::ORDER_CREATE_ERROR,
                [
                    'formParams' => $orderId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'errorType' => $e::class
                ]
            );
        }

        return null;
    }

    public function setOrderCustomFields(string $orderId, array $customFields): void
    {
        try {
            $this->orderRepository->update(
                [
                    [
                        'id'           => $orderId,
                        'customFields' => $customFields
                    ]
                ],
                Context::createDefaultContext()
            );
        } catch (\Exception $e) {
            $this->logEntryRepository->create(
                [
                    [
                        'message' => 'Could not update order customfields',
                        'context' => [
                            'error' => $e->getMessage(),
                            'errorMessage'=> $e->getTraceAsString(),
                            'orderId' => $orderId
                        ],
                        'level'   => Level::Critical->value,
                        'channel' => 'quickpay'
                    ]
                ],
                Context::createDefaultContext()
            );
        }
    }
}
