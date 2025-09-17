<?php declare(strict_types=1);

namespace Wexo\Quickpay\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Level;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\LogEntryCollection;
use Shopware\Core\System\Language\LanguageCollection;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Wexo\Quickpay\WexoQuickpay;

class QuickpayService
{
    /** @var Client[] $apiClients */
    protected array $apiClients = [];

    /**
     * @param EntityRepository<OrderTransactionCollection> $orderTransactionRepository
     * @param SystemConfigService $systemConfigService
     * @param EntityRepository<LogEntryCollection> $logEntryRepository
     * @param EntityRepository<LanguageCollection> $languageRepository
     * @param EntityRepository<OrderCollection> $orderRepository
     * @param OrderTransactionStateHandler $transactionStateHandler
     * @param StateMachineRegistry $stateMachineRegistry
     */
    public function __construct(
        private readonly EntityRepository $orderTransactionRepository,
        protected SystemConfigService $systemConfigService,
        protected EntityRepository $logEntryRepository,
        protected EntityRepository $languageRepository,
        protected EntityRepository $orderRepository,
        protected OrderTransactionStateHandler $transactionStateHandler,
        protected StateMachineRegistry $stateMachineRegistry
    ) {
    }

    /**
     * @param string $orderTransactionId
     * @param Context $context
     * @return OrderTransactionEntity
     */
    protected function loadTransaction(string $orderTransactionId, Context $context): OrderTransactionEntity
    {
        $criteria = new Criteria([$orderTransactionId])
            ->addAssociation('order.lineItems')
            ->addAssociation('order.currency')
            ->addAssociation('order.stateMachineState')
            ->addAssociation('stateMachineState')
            ->addAssociation('paymentMethod');
        // add more associations only if you need them later

        $tx = $this->orderTransactionRepository->search($criteria, $context)->first();
        if ($tx === null) {
            throw new \RuntimeException('Order transaction not found: ' . $orderTransactionId);
            // or PaymentException::invalidOrderTransaction($orderTransactionId)
        }

        return $tx;
    }

    public function getClient(?string $salesChannelId): Client
    {
        //If no string is supplied to system config service, it uses '_global_' under the hood.
        if ($salesChannelId === null || $salesChannelId === '') {
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

    /**
     * @param string $salesChannelId
     * @param string $content
     * @param string|null $submittedChecksum
     * @return bool
     */
    public function checkPrivateKey(
        string $salesChannelId,
        string $content,
        ?string $submittedChecksum
    ): bool {
        $key = $this->systemConfigService->get('WexoQuickpay.config.quickpayPrivateKey', $salesChannelId);
        if (!is_string($key)) {
            $key = '';
        }

        $checksum = hash_hmac('sha256', $content, $key);

        return $checksum === $submittedChecksum;
    }

    /**
     * @param array<string, array<mixed>|int|object|string> $config
     * @return bool
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

            /** @var array<string, mixed>|null $pKey */
            $pKey = json_decode($response->getBody()->getContents(), true);

            if (is_array($pKey) && isset($pKey['private_key']) && $pKey['private_key'] === $config['quickpayPrivateKey']) {
                return $response->getStatusCode() === 200;
            }

            return false;
        } catch (\Exception $exception) {
            $this->paymentLogger($exception->getMessage(), ['trace' => $exception->getTrace()]);

            return false;
        }
    }

    /**
     * @param string $event
     * @param array<string, mixed> $context
     * @return void
     */
    public function paymentLogger(
        string $event,
        array $context,
    ): void {
        $this->logEntryRepository->create(
            [
                [
                    'message' => $event,
                    'context' => $context,
                    'level' => Level::Error->value,
                    'channel' => WexoQuickpay::LOG_CHANNEL
                ]
            ],
            Context::createCLIContext()
        );
    }

    protected function getLanguage(string $languageId, Context $context): string
    {
        $criteria = new Criteria([$languageId]);
        $criteria->addAssociation('locale');

        /** @var LanguageEntity|null $language */
        $language = $this->languageRepository->search($criteria, $context)->first();

        $localeCode = $language?->getLocale()?->getCode();
        $base = $localeCode !== null && $localeCode !== ''
            ? strtolower(explode('-', $localeCode, 2)[0])
            : 'en';

        // Map both Norwegian locales to "no"
        $map = ['nb' => 'no', 'nn' => 'no'];

        return $map[$base] ?? $base;
    }

    /**
     * return string|null
     * @throws GuzzleException
     */
    public function updateResponse(
        string $orderId,
        mixed $paymentId = null,
        ?SalesChannelContext $context = null
    ): ?string {
        $context = $context !== null ? $context->getContext() : Context::createCLIContext();

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search(new Criteria([$orderId]), $context)->get($orderId);
        if ($order === null) {
            return null;
        }

        if ($paymentId === null || $paymentId === false || $paymentId === '') {
            $customFields = $order->getCustomFields();
            if ($customFields !== null && isset($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD])) {
                $data = json_decode((string) $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD]);

                if (\is_array($data) && isset($data['id'])) {
                    $paymentId = (string) $data['id'];
                }
            }
        }

        if ($paymentId === null || $paymentId === '') {
            return null;
        }

        try {
            $response = $this->getClient($order->getSalesChannelId())
                ->request('GET', 'payments/' . $paymentId);

            $content = (string) $response->getBody();

            if ($response->getStatusCode() === 200 && $content !== '') {
                $customFields = $order->getCustomFields();
                if (!\is_array($customFields)) {
                    $customFields = [];
                }

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

    /**
     * @param string $orderId
     * @param array<string, mixed> $customFields
     * @return void
     */
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
                Context::createCLIContext()
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
                Context::createCLIContext()
            );
        }
    }
}
