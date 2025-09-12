<?php declare(strict_types=1);

namespace Wexo\Quickpay\Core\Checkout\Cart\SalesChannel;

use Shopware\Core\Checkout\Cart\AbstractCartPersister;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartCalculator;
use Shopware\Core\Checkout\Cart\Order\OrderPersisterInterface;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartOrderRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartOrderRouteResponse;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Wexo\Quickpay\WexoQuickpay;

class CartOrderRouteDecorator extends AbstractCartOrderRoute
{
    /**
     * @param AbstractCartOrderRoute $decoratedService
     * @param CartCalculator $cartCalculator
     * @param EntityRepository<OrderCollection> $orderRepository
     * @param EntityRepository<OrderCustomerCollection> $orderCustomerRepository
     * @param OrderPersisterInterface $orderPersister
     * @param AbstractCartPersister $cartPersister
     * @param EventDispatcherInterface $eventDispatcher
     * @param EntityRepository<OrderTransactionCollection> $orderTransactionRepository
     * @param PluginIdProvider $pluginIdProvider
     */
    public function __construct(
        protected AbstractCartOrderRoute $decoratedService,
        protected CartCalculator $cartCalculator,
        protected EntityRepository $orderRepository,
        protected EntityRepository $orderCustomerRepository,
        protected OrderPersisterInterface $orderPersister,
        protected AbstractCartPersister $cartPersister,
        protected EventDispatcherInterface $eventDispatcher,
        protected EntityRepository $orderTransactionRepository,
        protected PluginIdProvider $pluginIdProvider
    ) {
    }

    /**
     * @return AbstractCartOrderRoute
     */
    public function getDecorated(): AbstractCartOrderRoute
    {
        return $this->decoratedService->getDecorated();
    }

    /**
     * @param Cart $cart
     * @param SalesChannelContext $context
     * @param RequestDataBag $data
     * @return CartOrderRouteResponse
     */
    public function order(
        Cart $cart,
        SalesChannelContext $context,
        RequestDataBag $data
    ): CartOrderRouteResponse {
        $originalCart = $this->cartPersister->load($context->getToken(), $context);

        $response = $this->decoratedService->order($cart, $context, $data);

        // Restore cart if QuickPay payment method was used
        $this->restoreCartIfQuickpay($originalCart, $response->getOrder(), $context);

        return $response;
    }

    /**
     * @param Cart $cart
     * @param OrderEntity $orderEntity
     * @param SalesChannelContext $context
     * @return void
     */
    protected function restoreCartIfQuickpay(Cart $cart, OrderEntity $orderEntity, SalesChannelContext $context): void
    {
        $criteria = new Criteria()
            ->addFilter(new EqualsFilter('orderId', $orderEntity->getId()))
            ->addAssociation('paymentMethod');

        /** @var OrderTransactionEntity $orderTransaction */
        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context->getContext())->first();

        if ($orderTransaction) {
            /** @var PaymentMethodEntity $paymentMethod */
            $paymentMethod = $orderTransaction->getPaymentMethod();

            $pluginId = $this->pluginIdProvider->getPluginIdByBaseClass(
                WexoQuickpay::class,
                $context->getContext()
            );

            // If a QuickPay payment method was used we restore the cart
            // If a QuickPay method is used the cart will be cleared in QuickPayPayment::finalize()
            if ($paymentMethod->getPluginId() === $pluginId) {
                $this->cartPersister->save($cart, $context);
            }
        }
    }
}
