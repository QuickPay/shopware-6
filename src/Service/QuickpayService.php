<?php declare(strict_types=1);

namespace Wexo\Quickpay\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Wexo\Quickpay\WexoQuickpay;

/**
 * Class SubscriptionService
 * @package Wexo\Quickpay\Service
 */
class QuickpayService
{
    /** @var Client[] $apiClients */
    protected array $apiClients = [];
    protected SystemConfigService $systemConfigService;
    protected EntityRepositoryInterface $logEntryRepository;
    protected EntityRepositoryInterface $languageRepository;
    protected EntityRepositoryInterface $orderRepository;
    protected OrderTransactionStateHandler $transactionStateHandler;
    protected StateMachineRegistry $stateMachineRegistry;

    /**
     * @param SystemConfigService $systemConfigService
     * @param EntityRepositoryInterface $logEntryRepository
     * @param EntityRepositoryInterface $languageRepository
     * @param EntityRepositoryInterface $orderRepository
     * @param OrderTransactionStateHandler $transactionStateHandler
     * @param StateMachineRegistry $stateMachineRegistry
     */
    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepositoryInterface $logEntryRepository,
        EntityRepositoryInterface $languageRepository,
        EntityRepositoryInterface $orderRepository,
        OrderTransactionStateHandler $transactionStateHandler,
        StateMachineRegistry $stateMachineRegistry
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->logEntryRepository = $logEntryRepository;
        $this->languageRepository = $languageRepository;
        $this->orderRepository = $orderRepository;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->stateMachineRegistry = $stateMachineRegistry;
    }

    /**
     * @param string|null $salesChannelId
     * @return Client
     */
    public function getClient(?string $salesChannelId): Client
    {
        //If no string is supplied to system config service, it uses 'global' under the hood.
        if (!$salesChannelId) {
            $salesChannelId = 'global';
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
     * @param AsyncPaymentTransactionStruct $transaction
     * @param string $content
     * @param string $submittedChecksum
     * @return bool
     */
    public function checkPrivateKey(
        string $salesChannelId,
        string $content,
        string $submittedChecksum
    ): bool {
        $key = $this->systemConfigService->get('WexoQuickpay.config.quickpayPrivateKey', $salesChannelId);

        $checksum = hash_hmac('sha256', $content, $key);
        if ($checksum !== $submittedChecksum) {
            return false;
        }

        return true;
    }

    /**
     * @param array $config
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

    /**
     * @param string $event
     * @param array $context
     * @param int $level
     */
    public function paymentLogger(
        string $event,
        array $context,
        int $level = Logger::ERROR
    ) {
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

    /**
     * @param string $languageId
     * @param Context $context
     * @return string
     */
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

        $language = explode('-', $language->getLocale()->getCode())[0];

        if (isset($map[$language])) {
            return $map[$language];
        }

        return $language;
    }

    /**
     * @param string $orderId
     * @param null $paymentId
     * @param SalesChannelContext|null $context
     * @return string|null
     * @throws GuzzleException
     */
    public function updateResponse(
        string $orderId,
        $paymentId = null,
        ?SalesChannelContext $context = null
    ): ?string {
        try {
            $context = $context ? $context->getContext() : Context::createDefaultContext();

            /** @var OrderEntity $order */
            $order = $this->orderRepository->search(new Criteria([$orderId]), $context)->first();
            if (! $order) {
                return null;
            }

            if (! $paymentId) {
                $criteria = new Criteria([$orderId]);
                $criteria->addAssociation('customFields');
                $order = $this->orderRepository->search($criteria, $context)->first();

                $customFields = $order->getCustomFields();
                if ($customFields && isset($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD])) {
                    $data = json_decode($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD]);

                    if (property_exists($data, 'id')) {
                        $paymentId = $data->id;
                    }
                }
            }

            if (! $paymentId) {
                return null;
            }

            $response = $this->getClient($order->getSalesChannelId())
                ->request('GET', 'payments/' . $paymentId);
            $content = $response->getBody()->getContents();
            if ($response->getStatusCode() === 200 && $content) {
                $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD] = $content;
                $this->setOrderCustomFields($orderId, $customFields);

                return $content;
            }
        } catch (\Error | \TypeError | \Exception $e) {
            $this->paymentLogger(
                WexoQuickpay::ORDER_CREATE_ERROR,
                [
                    'formParams' => $orderId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'errorType' => get_class($e)
                ]
            );
        }

        return null;
    }

    /**
     * @param string $orderId
     * @param array $customFields
     * @return void
     */
    public function setOrderCustomFields(string $orderId, array $customFields)
    {
        $this->orderRepository->update(
            [
                [
                    'id'           => $orderId,
                    'customFields' => $customFields
                ]
            ],
            Context::createDefaultContext()
        );
    }
}
