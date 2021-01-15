<?php declare(strict_types=1);

namespace Wexo\Quickpay;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Wexo\Quickpay\Service\QuickpayPayment;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Wexo\Quickpay\Service\MobilepayPayment;
use Wexo\Quickpay\Service\KlarnaPayment;

/**
 * Class WexoQuickpay
 * @package Wexo\Quickpay
 */
class WexoQuickpay extends Plugin
{
    public const DEFAULT_PAYMENT_METHODS = [
        'MobilePay' => 'MobilePay from QuickPay',
        'Credit Card' => 'Credit cards from QuickPay',
        'Klarna' => 'Klarna from QuickPay'
    ];
    public const FALLBACK_CURRENCY = 'EUR';
    public const QUICKPAY_RESPONSE_FIELD = 'quickpay_response';
    public const LOG_CHANNEL = 'quickpay';
    public const ORDER_CREATE_SUCCESS = 'quickpay.order.create.success';
    public const ORDER_CREATE_ERROR = 'quickpay.order.create.error';
    public const ORDER_COMPLETE_SUCCESS = 'quickpay.order.finalize.success';
    public const ORDER_COMPLETE_ERROR = 'quickpay.order.finalize.error';

    /**
     * @param InstallContext $installContext
     */
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);

        $customFieldSetRepository = $this->container->get('custom_field_set.repository');

        $customFieldSetRepository->upsert([[
            'name' => 'quickpay',
            'customFields' => [
                [
                    'name' => self::QUICKPAY_RESPONSE_FIELD,
                    'type' => CustomFieldTypes::JSON,
                    'config' => [
                        'label' => [
                            'da-DK' => 'QuickPay svar',
                            'en-GB' => 'QuickPay response',
                            'de-DE' => 'QuickPay-Antwort',
                        ]
                    ]
                ]
            ],
            'config' => [
                'label' => [
                    'da-DK' => 'QuickPay',
                    'en-GB' => 'QuickPay',
                    'de-DE' => 'QuickPay',
                ]
            ],
            'relations' => [
                [
                    'entityName' => 'order',
                ],
            ],
        ]], Context::createDefaultContext());
    }

    /**
     * @param InstallContext $installContext
     */
    public function postInstall(InstallContext $installContext): void
    {
        $context = Context::createDefaultContext();
        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(WexoQuickpay::class, $context);

        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        foreach (self::DEFAULT_PAYMENT_METHODS as $name => $description) {
            $handlerIdentifier = QuickpayPayment::class;
            switch ($name) {
                case "MobilePay":
                    $handlerIdentifier = MobilepayPayment::class;
                    break;
                case "Klarna":
                    $handlerIdentifier = KlarnaPayment::class;
                    break;
            };
            $paymentMethodData = [
                'handlerIdentifier' => $handlerIdentifier,
                'name' => $name,
                'description' => $description,
                'pluginId' => $pluginId,
            ];
            $paymentRepository->upsert([$paymentMethodData], $context);
        }
    }

    /**
     * @param UpdateContext $updateContext
     */
    public function update(UpdateContext $updateContext): void
    {
        $context = Context::createDefaultContext();
        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(WexoQuickpay::class, $context);
        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        foreach (self::DEFAULT_PAYMENT_METHODS as $name => $description) {
            $handlerIdentifier = QuickpayPayment::class;
            switch ($name) {
                case "MobilePay":
                    $handlerIdentifier = MobilepayPayment::class;
                    break;
                case "Klarna":
                    $handlerIdentifier = KlarnaPayment::class;
                    break;
            };

            $paymentMethodData = [
                'handlerIdentifier' => $handlerIdentifier,
                'name' => $name,
                'description' => $description,
                'pluginId' => $pluginId,
            ];
            $paymentRepository->upsert([$paymentMethodData], $context);
        }
    }

    /**
     * @param UninstallContext $uninstallContext
     */
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);
    }
}
