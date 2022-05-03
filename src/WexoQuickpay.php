<?php declare(strict_types=1);

namespace Wexo\Quickpay;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Wexo\Quickpay\Service\QuickpayPayment;
use Wexo\Quickpay\Service\MobilepayPayment;
use Wexo\Quickpay\Service\KlarnaPayment;
use Wexo\Quickpay\Service\SwishPayment;
use Wexo\Quickpay\Service\ViabillPayment;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;

/**
 * Class WexoQuickpay
 * @package Wexo\Quickpay
 */
class WexoQuickpay extends Plugin
{
    public const DEFAULT_PAYMENT_METHODS = [
        'MobilePay' => 'MobilePay from QuickPay',
        'Credit Card' => 'Credit cards from QuickPay',
        'Klarna' => 'Klarna from QuickPay',
        'Viabill' => 'Viabill from QuickPay',
        'Swish' => 'Swish from QuickPay'
    ];
    public const QUICKPAY_FIELD_SET = 'quickpay';
    public const QUICKPAY_RESPONSE_FIELD = 'quickpay_response';
    public const QUICKPAY_SUBSCRIPTION_ID = 'quickpay_subscription_id';
    public const LOG_CHANNEL = 'quickpay';
    public const ORDER_CREATE_SUCCESS = 'quickpay.order.create.success';
    public const ORDER_CREATE_ERROR = 'quickpay.order.create.error';
    public const ORDER_COMPLETE_SUCCESS = 'quickpay.order.finalize.success';
    public const ORDER_COMPLETE_ERROR = 'quickpay.order.finalize.error';
    public const ORDER_CANCEL_ERROR = 'quickpay.order.cancel.error';

    /**
     * @param InstallContext $installContext
     */
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);

        $customFieldSetRepository = $this->container->get('custom_field_set.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::QUICKPAY_FIELD_SET));

        /** @var CustomFieldSetEntity $customFieldSet */
        $customFieldSet = $customFieldSetRepository->search(
            $criteria,
            $installContext->getContext()
        )->first();

        if (! $customFieldSet) {
            $customFieldSetRepository->upsert([[
                'name' => self::QUICKPAY_FIELD_SET,
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
            ]], $installContext->getContext());
        }

        $this->addPaymentMethods($installContext->getContext());
    }

    /**
     * Only set the payment method to inactive when uninstalling.  Removing the payment method would
     * cause data consistency issues, since the payment method might have been used in several orders
     *
     * @param UninstallContext $context
     */
    public function uninstall(UninstallContext $context): void
    {
        parent::uninstall($context);
        foreach (self::DEFAULT_PAYMENT_METHODS as $name => $description) {
            $handlerIdentifier = QuickpayPayment::class;
            switch ($name) {
                case "MobilePay":
                    $handlerIdentifier = MobilepayPayment::class;
                    break;
                case "Klarna":
                    $handlerIdentifier = KlarnaPayment::class;
                    break;
                case "Viabill":
                    $handlerIdentifier = ViabillPayment::class;
                    break;
                case "Swish":
                    $handlerIdentifier = SwishPayment::class;
                    break;
            };
            $paymentMethodId = $this->getPaymentMethodId($handlerIdentifier);
            $this->setPaymentMethodIsActive(false, $context->getContext(), $paymentMethodId);
        }
    }

    public function update(UpdateContext $context): void
    {
        if (version_compare($context->getCurrentPluginVersion(), '3.0.3', '<')) {
            $oldMobilePayId = $this->getPaymentMethodIdByName(QuickpayPayment::class, 'MobilePay');
            if ($oldMobilePayId) {
                $paymentRepository = $this->container->get('payment_method.repository');
                $paymentMethod = [
                    'id' => $oldMobilePayId,
                    'handlerIdentifier' => MobilepayPayment::class,
                ];
                $paymentRepository->update([$paymentMethod], Context::createDefaultContext());
            }
        }

        if (version_compare($context->getCurrentPluginVersion(), '6.0.0', '>')) {
            $customFieldSetRepository = $this->container->get('custom_field_set.repository');

            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('name', self::QUICKPAY_FIELD_SET));

            /** @var CustomFieldSetEntity $customFieldSet */
            $customFieldSet = $customFieldSetRepository->search(
                $criteria,
                $context->getContext()
            )->first();

            if ($customFieldSet) {
                $customFieldSetRepository->upsert([
                    [
                        'id'           => $customFieldSet->getId(),
                        'customFields' => [
                            [
                                'name'   => self::QUICKPAY_SUBSCRIPTION_ID,
                                'type'   => CustomFieldTypes::TEXT,
                                'config' => [
                                    'label'               => 'Subscription ID',
                                    'componentName'       => 'sw-field',
                                    'customFieldType'     => CustomFieldTypes::TEXT,
                                    'customFieldPosition' => 2,
                                ],
                            ]
                        ]
                    ]
                ], $context->getContext());
            }
        }

        $this->addPaymentMethods(Context::createDefaultContext());
    }

    /**
     * @param ActivateContext $context
     */
    public function activate(ActivateContext $context): void
    {
        foreach (self::DEFAULT_PAYMENT_METHODS as $name => $description) {
            $handlerIdentifier = QuickpayPayment::class;
            switch ($name) {
                case "MobilePay":
                    $handlerIdentifier = MobilepayPayment::class;
                    break;
                case "Klarna":
                    $handlerIdentifier = KlarnaPayment::class;
                    break;
                case "Viabill":
                    $handlerIdentifier = ViabillPayment::class;
                    break;
                case "Swish":
                    $handlerIdentifier = SwishPayment::class;
                    break;
            };
            $paymentMethodId = $this->getPaymentMethodId($handlerIdentifier);
            $this->setPaymentMethodIsActive(true, $context->getContext(), $paymentMethodId);
        }
        parent::activate($context);
    }

    /**
     * @param DeactivateContext $context
     */
    public function deactivate(DeactivateContext $context): void
    {
        foreach (self::DEFAULT_PAYMENT_METHODS as $name => $description) {
            $handlerIdentifier = QuickpayPayment::class;
            switch ($name) {
                case "MobilePay":
                    $handlerIdentifier = MobilepayPayment::class;
                    break;
                case "Klarna":
                    $handlerIdentifier = KlarnaPayment::class;
                    break;
                case "Viabill":
                    $handlerIdentifier = ViabillPayment::class;
                    break;
                case "Swish":
                    $handlerIdentifier = SwishPayment::class;
                    break;
            };
            $paymentMethodId = $this->getPaymentMethodId($handlerIdentifier);
            $this->setPaymentMethodIsActive(false, $context->getContext(), $paymentMethodId);
        }
        parent::deactivate($context);
    }

    /**
     * @param Context $context
     */
    private function addPaymentMethods(Context $context): void
    {
        $paymentRepository = $this->container->get('payment_method.repository');
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(WexoQuickpay::class, $context);

        foreach (self::DEFAULT_PAYMENT_METHODS as $name => $description) {
            $handlerIdentifier = QuickpayPayment::class;
            switch ($name) {
                case "MobilePay":
                    $handlerIdentifier = MobilepayPayment::class;
                    break;
                case "Klarna":
                    $handlerIdentifier = KlarnaPayment::class;
                    break;
                case "Viabill":
                    $handlerIdentifier = ViabillPayment::class;
                    break;
                case "Swish":
                    $handlerIdentifier = SwishPayment::class;
                    break;
            };

            $paymentMethodExists = $this->getPaymentMethodId($handlerIdentifier);
            // Payment method exists already, no need to continue here
            if ($paymentMethodExists) {
                continue;
            }

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
     * @param bool $active
     * @param Context $context
     * @param $paymentMethodId
     */
    private function setPaymentMethodIsActive(bool $active, Context $context, $paymentMethodId): void
    {
        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        // Payment does not even exist, so nothing to (de-)activate here
        if (!$paymentMethodId) {
            return;
        }
        $paymentMethod = [
            'id' => $paymentMethodId,
            'active' => $active,
        ];
        $paymentRepository->update([$paymentMethod], $context);
    }

    /**
     * @param $identifier
     * @return string|null
     */
    private function getPaymentMethodId($identifier): ?string
    {
        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        // Fetch ID for update
        $paymentCriteria = (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', $identifier));
        $paymentId = $paymentRepository->searchIds($paymentCriteria, Context::createDefaultContext())->firstId();
        if (empty($paymentId)) {
            return null;
        }
        return $paymentId;
    }

    private function getPaymentMethodIdByName($identifier, $name): ?string
    {
        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        // Fetch ID for update
        $paymentCriteria = (new Criteria())
            ->addFilter(new EqualsFilter('handlerIdentifier', $identifier))
            ->addFilter(new ContainsFilter('name', $name));
        $paymentId = $paymentRepository->searchIds($paymentCriteria, Context::createDefaultContext())->firstId();
        if (empty($paymentId)) {
            return null;
        }
        return $paymentId;
    }
}
