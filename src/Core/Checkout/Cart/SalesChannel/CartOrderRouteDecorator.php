<?php

namespace Wexo\Quickpay\Core\Checkout\Cart\SalesChannel;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartCalculator;
use Shopware\Core\Checkout\Cart\CartPersisterInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\Order\OrderPersisterInterface;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartOrderRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartOrderRouteResponse;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Exception\InvalidOrderException;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Wexo\Quickpay\WexoQuickpay;

class CartOrderRouteDecorator extends AbstractCartOrderRoute
{
    /**
     * @var \Shopware\Core\Checkout\Cart\SalesChannel\CartOrderRoute
     */
    protected $decoratedService;
    /**
     * @var CartCalculator
     */
    protected $cartCalculator;

    /**
     * @var EntityRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var OrderPersisterInterface
     */
    protected $orderPersister;

    /**
     * @var CartPersisterInterface
     */
    protected $cartPersister;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * @var EntityRepositoryInterface
     */
    protected $orderCustomerRepository;

    /**
     * @var EntityRepositoryInterface
     */
    protected $orderTransactionRepository;

    /**
     * @var PluginIdProvider
     */
    protected $pluginIdProvider;

    /**
     * CartOrderRouteDecorator constructor.
     * @param \Shopware\Core\Checkout\Cart\SalesChannel\CartOrderRoute $cartOrderRoute
     * @param CartCalculator $cartCalculator
     * @param EntityRepositoryInterface $orderRepository
     * @param EntityRepositoryInterface $orderCustomerRepository
     * @param OrderPersisterInterface $orderPersister
     * @param CartPersisterInterface $cartPersister
     * @param EventDispatcherInterface $eventDispatcher
     * @param EntityRepositoryInterface $orderTransactionRepository
     * @param PluginIdProvider $pluginIdProvider
     */
    public function __construct(
        \Shopware\Core\Checkout\Cart\SalesChannel\CartOrderRoute $cartOrderRoute,
        CartCalculator $cartCalculator,
        EntityRepositoryInterface $orderRepository,
        EntityRepositoryInterface $orderCustomerRepository,
        OrderPersisterInterface $orderPersister,
        CartPersisterInterface $cartPersister,
        EventDispatcherInterface $eventDispatcher,
        EntityRepositoryInterface $orderTransactionRepository,
        PluginIdProvider $pluginIdProvider
    ) {
        $this->decoratedService = $cartOrderRoute;
        $this->cartCalculator = $cartCalculator;
        $this->orderRepository = $orderRepository;
        $this->orderCustomerRepository = $orderCustomerRepository;
        $this->orderPersister = $orderPersister;
        $this->cartPersister = $cartPersister;
        $this->eventDispatcher = $eventDispatcher;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->pluginIdProvider = $pluginIdProvider;
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
     * @return CartOrderRouteResponse
     */
    public function order(Cart $cart, SalesChannelContext $context): CartOrderRouteResponse
    {
        $calculatedCart = $this->cartCalculator->calculate($cart, $context);
        $orderId = $this->orderPersister->persist($calculatedCart, $context);

        $criteria = new Criteria([$orderId]);
        $criteria
            ->addAssociation('deliveries.shippingMethod')
            ->addAssociation('deliveries.shippingOrderAddress.country')
            ->addAssociation('transactions.paymentMethod')
            ->addAssociation('lineItems')
            ->addAssociation('currency')
            ->addAssociation('addresses.country');

        /** @var OrderEntity|null $orderEntity */
        $orderEntity = $this->orderRepository->search($criteria, $context->getContext())->first();

        if (!$orderEntity) {
            throw new InvalidOrderException($orderId);
        }

        $orderEntity->setOrderCustomer(
            $this->fetchCustomer($orderEntity->getId(), $context->getContext())
        );

        $orderPlacedEvent = new CheckoutOrderPlacedEvent(
            $context->getContext(),
            $orderEntity,
            $context->getSalesChannel()->getId()
        );

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderEntity->getId()));
        $criteria->addAssociation('paymentMethod');

        /** @var OrderTransactionEntity $orderTransaction */
        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context->getContext());

        if ($orderTransaction && $orderTransaction = $orderTransaction->first()) {
            /** @var PaymentMethodEntity $paymentMethod */
            $paymentMethod = $orderTransaction->getPaymentMethod();

            $pluginId = $this->pluginIdProvider->getPluginIdByBaseClass(WexoQuickpay::class, $context->getContext());

            // Only clear cart here if payment_method is not a quickpay method
            // If a quickpay method is used the cart will be cleared in QuickPayPayment::finalize()
            if ($paymentMethod->getPluginId() !== $pluginId) {
                $this->cartPersister->delete($context->getToken(), $context);
            }
        }

        $this->eventDispatcher->dispatch($orderPlacedEvent);

        return new CartOrderRouteResponse($orderEntity);
    }

    /**
     * @param string $orderId
     * @param Context $context
     * @return OrderCustomerEntity
     */
    private function fetchCustomer(string $orderId, Context $context): OrderCustomerEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addAssociation('customer');
        $criteria->addAssociation('salutation');

        return $this->orderCustomerRepository
            ->search($criteria, $context)
            ->first();
    }
}
