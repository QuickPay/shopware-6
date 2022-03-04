<?php


declare(strict_types=1);

namespace QuickPay;

use QuickPay\Service\PaymentMethod;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;

abstract class PaymentPlugin extends Plugin
{
    public function install(InstallContext $context): void
    {
        $this->addPaymentMethods($context->getContext());
    }

    public function update(Plugin\Context\UpdateContext $context): void
    {
        $this->addPaymentMethods($context->getContext());
    }

    public function uninstall(UninstallContext $context): void
    {
        $this->setPaymentMethodsIsActive(false, $context->getContext());
    }

    public function activate(ActivateContext $context): void
    {
        $this->setPaymentMethodsIsActive(true, $context->getContext());
        parent::activate($context);
    }

    private function addPaymentMethod($paymentMethodClass, Context $context): void
    {
        $paymentMethodExists = $this->getPaymentMethodId($paymentMethodClass);
        // Payment method exists already, no need to continue here
        if ($paymentMethodExists) {
            return;
        }

        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(get_class($this), $context);
        $paymentData = [
            // payment handler will be selected by the identifier
            'handlerIdentifier' => $paymentMethodClass,
            'name' => $paymentMethodClass::$name,
            'description' => $paymentMethodClass::$description,
            'pluginId' => $pluginId,
        ];
        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        $paymentRepository->create([$paymentData], $context);
    }

    private function removePaymentMethod($paymentMethodClass, Context $context): void
    {
        $paymentMethodId = $this->getPaymentMethodId($paymentMethodClass);
        // Payment method doesn't exists, no need to continue here
        if (!$paymentMethodId) {
            return;
        }

        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        $paymentRepository->delete([$paymentMethodId], $context);
    }

    private function setPaymentMethodsIsActive(bool $active, Context $context): void
    {
        foreach ($this->getPaymentMethodClasses() as $paymentMethodClass) {
            $this->setPaymentMethodIsActive($paymentMethodClass, $active, $context);
        }
    }
    public function deactivate(DeactivateContext $context): void
    {
        $this->setPaymentMethodsIsActive(false, $context->getContext());
        parent::deactivate($context);
    }

    protected abstract function getPaymentMethodClasses(): array;
    private function addPaymentMethods(Context $context)
    {
        foreach ($this->getPaymentMethodClasses() as $paymentMethodClass) {
            $this->addPaymentMethod($paymentMethodClass, $context);
        }
    }
    private function setPaymentMethodIsActive($paymentMethodClass, bool $active, Context $context): void
    {
        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        $paymentMethodId = $this->getPaymentMethodId($paymentMethodClass);
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

    private function getPaymentMethodId($paymentMethodClass): ?string
    {
        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        // Fetch ID for update
        $paymentCriteria = (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', $paymentMethodClass));
        return $paymentRepository->searchIds($paymentCriteria, Context::createDefaultContext())->firstId();
    }
}
