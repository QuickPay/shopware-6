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
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Wexo\Quickpay\WexoQuickpay;

class CartOrderRouteDecorator extends AbstractCartOrderRoute
{
    /**
     * @var AbstractCartOrderRoute
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
     * @param AbstractCartOrderRoute $cartOrderRoute
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
        AbstractCartOrderRoute $cartOrderRoute,
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
     * @param RequestDataBag|null $data
     * @return CartOrderRouteResponse
     */
    public function order(
        Cart $cart,
        SalesChannelContext $context,
        ?RequestDataBag $data = null
    ): CartOrderRouteResponse {
        $originalCart = $this->cartPersister->load($context->getToken(), $context);

        $response = $this->decoratedService->order($cart, $context, $data);

        // Restore cart if quickpay payment method was used
        $this->restoreCartIfQuickpay($originalCart, $response->getOrder(), $context);

        return $response;
    }

    /**
     * @param Cart $cart
     * @param OrderEntity $orderEntity
     * @param SalesChannelContext $context
     */
    protected function restoreCartIfQuickpay(Cart $cart, OrderEntity $orderEntity, SalesChannelContext $context)
    {
        $criteria = (new Criteria())
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

            // If a quickpay payment method was used we restore the cart
            // If a quickpay method is used the cart will be cleared in QuickPayPayment::finalize()
            if ($paymentMethod->getPluginId() === $pluginId) {
                $this->cartPersister->save($cart, $context);
            }
        }
    }
}
