<?php declare(strict_types=1);

namespace Wexo\Quickpay\Controller;

use Monolog\Logger;
use Shopware\Core\Checkout\Cart\AbstractCartPersister;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderException;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenFactoryInterfaceV2;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Checkout\Payment\PaymentService;
use Shopware\Core\Content\Flow\Dispatching\Action\SetOrderStateAction;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\PartialEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Routing\RoutingException;
use Shopware\Core\Profiling\Profiler;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Wexo\Quickpay\Framework\Routing\QuickpayRouteScope;
use Wexo\Quickpay\WexoQuickpay;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class QuickpayStorefrontController extends AbstractController
{
    public const CALLBACK = 'quickpay_callback';

    public function __construct(
        protected EntityRepository $logEntryRepository,
        protected PaymentService $paymentService,
        protected TokenFactoryInterfaceV2 $tokenFactory,
        protected AbstractCartPersister $cartPersister,
        protected UrlGeneratorInterface $urlGenerator,
        # Callback
        protected EntityRepository $orderRepository,
        protected SystemConfigService $configService,
        protected StateMachineRegistry $stateMachineRegistry
    ) {
    }

    #[Route(
        path: '/payment/quickpay-finalize-transaction',
        defaults: ['auth_required' => false, 'csrf_protected' => false],
        methods: ['POST', 'GET']
    )]
    public function quickpayFinalizeTransaction(
        Request $request,
        SalesChannelContext $context
    ): JsonResponse|RedirectResponse {
        $finalizeAllowed = true;
        $data = [];
        $paymentToken = $request->get('_sw_payment_token');
        $status = $request->query->get('status');
        $forbiddenStatuses = [30100, 30101, 40000, 40001, 50000, 50300];

        $operations = $request->get('operations');
        if (!empty($operations)) {
            $operation = end($operations);
            /*
             * 30100 and 30101 indicate errors based on rejected 3D Secure
             * https://learn.quickpay.net/tech-talk/appendixes/errors/
             */
            if (!isset($operation['qp_status_code']) || in_array($operation['qp_status_code'], $forbiddenStatuses)) {
                $finalizeAllowed = false;
            }
        }

        $this->logEntryRepository->create(
            [
                [
                    'message' => 'quickpay_finalize_transaction_debug',
                    'context' => (array)$request->getContent(),
                    'level' => Logger::DEBUG,
                    'channel' => WexoQuickpay::LOG_CHANNEL
                ]
            ],
            $context->getContext()
        );

        /* Delete cart when either customer or quickpay reaches this page.
         * This just runs executeStatement, which just returns number of rows affected,
         * so multiple runs on runs non existing cart does not throw an error */
        if ($status === "accepted") {
            $this->cartPersister->delete($context->getToken(), $context);
        }

        if (in_array($status, ['accepted', 'cancel'])) {
            $token = $this->tokenFactory->parseToken($paymentToken);
            $url = ($status == 'accepted' ? $token->getFinishUrl() :
                ($token->getErrorUrl() ?:
                    $this->urlGenerator->generate(
                        'frontend.checkout.confirm.page',
                        [],
                        UrlGeneratorInterface::ABSOLUTE_URL
                    ))
            );
            return new RedirectResponse($url);
        }

        $paymentToken = $request->get('_sw_payment_token');

        if ($finalizeAllowed) {
            try {
                $result = $this->paymentService->finalizeTransaction(
                    $paymentToken,
                    $request,
                    $context
                );

                $exception = $result->getException();
                if ($exception) {
                    $data = [
                        'error' => 'payment_finalize_exception',
                        'errorMessage' => $exception->getMessage(),
                        'sw_status_code' => 400001
                    ];
                }
            } catch (PaymentException $exception) {
                if ($exception->is(PaymentException::PAYMENT_TOKEN_INVALIDATED)) {
                    $data = [
                        'error' => 'token_invalidated_exception',
                        'errorMessage' => $exception->getMessage(),
                        'sw_status_code' => 400002
                    ];
                } else {
                    $data = [
                        'error' => $exception->getErrorCode(),
                        'errorMessage' => $exception->getMessage(),
                        'sw_status_code' => 400002
                    ];
                }
            } catch (\Exception $exception) {
                $data = [
                    'error' => 'quick_pay_finalize_exception',
                    'errorMessage' => $exception->getMessage(),
                    'sw_status_code' => 400003
                ];
            }
        }

        if ($data) {
            if ($request->getContent()) {
                $data['content'] = json_decode((string)$request->getContent(), true);
            }
            $errorLevel = Logger::ERROR;
            $logMessage = 'quickpay_finalize_transaction_error';
            if (isset($data['sw_status_code']) && $data['sw_status_code'] == 400002) {
                $errorLevel = Logger::WARNING;
                $logMessage = 'quickpay_finalize_transaction_token_invalidated';
            }
            $this->logEntryRepository->create(
                [
                    [
                        'message' => $logMessage,
                        'context' => $data,
                        'level' => $errorLevel,
                        'channel' => WexoQuickpay::LOG_CHANNEL
                    ]
                ],
                $context->getContext()
            );
            if (isset($data['errorMessage'])) {
                unset($data['errorMessage']);
            }
        }

        return new JsonResponse($data, !empty($data) ? Response::HTTP_BAD_REQUEST : Response::HTTP_OK);
    }

    #[Route(
        path: '/quickpay/callback',
        name: 'quickpay.payment.callback',
        defaults: [
            'auth_required' => false,
            '_routeScope' => [QuickpayRouteScope::ID],
        ],
        methods: ['POST']
    )]
    public function callback(Context $context, Request $request): Response
    {
        $context->addState(self::CALLBACK);

        return Profiler::trace('quickpay-callback', function () use ($context, $request) {
            $orderNumber = $request->request->getString('order_id');
            if (!$orderNumber) {
                throw RoutingException::missingRequestParameter('order_id');
            }

            $sha256 = $request->headers->get('Quickpay-Checksum-Sha256');
            if (!$sha256) {
                throw HttpException::fromStatusCode(
                    Response::HTTP_BAD_REQUEST,
                    'Missing Quickpay-Checksum-Sha256 header'
                );
            }

            $type = strtolower($request->headers->get('QuickPay-Resource-Type', ''));
            if ($type !== 'payment' && $type !== 'subscription') {
                return new Response(null, Response::HTTP_NOT_IMPLEMENTED);
            }

            /** @var array<int, array> $operations */
            $operations = $request->request->all()['operations'] ?? [];

            /** @var array $currentOperation */
            $currentOperation = end($operations) ?: [];
            if (!$currentOperation) {
                throw HttpException::fromStatusCode(
                    Response::HTTP_BAD_REQUEST,
                    'Invalid Quickpay operations'
                );
            }

            if ((int)($currentOperation['qp_status_code'] ?? 0) !== 20000) {
                // We only want to process successful transactions
                return new Response(null, Response::HTTP_NO_CONTENT);
            }

            /**
             * Broaden the scope and use order salesChannelId
             *  to ensure correct salesChannelId amongst multiple sales channels
             */
            $criteria = (new Criteria())
                ->addFilter(new EqualsFilter('orderNumber', $orderNumber))
                ->addAssociation('stateMachineState')
                ->addFields([
                    'id',
                    'salesChannelId',
                    'transactions.id',
                    'transactions.paymentMethodId',
                    'transactions.stateMachineStateId',
                    'transactions.stateMachineState.id',
                    'transactions.stateMachineState.technicalName',
                    'deliveries.id',
                    'deliveries.stateMachineStateId',
                    'deliveries.stateMachineState.id',
                    'deliveries.stateMachineState.technicalName',
                ]);
            $criteria->getAssociation('transactions')
                ->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING))
                ->setLimit(1)
                ->addAssociation('stateMachineState');
            $criteria->getAssociation('deliveries')
                ->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING))
                ->setLimit(1)
                ->addAssociation('stateMachineState');

            /** @var PartialEntity|null $order */
            $order = $this->orderRepository->search(
                $criteria,
                $context
            )->first();
            if (!$order) {
                throw OrderException::orderNotFound($orderNumber);
            }
            unset($orderNumber);

            $privateKey = $this->configService->getString(
                'WexoQuickpay.config.quickpayPrivateKey',
                $order->get('salesChannelId')
            );
            if (!$privateKey) {
                throw HttpException::fromStatusCode(
                    Response::HTTP_BAD_REQUEST,
                    'Invalid or missing private key'
                );
            }

            if (hash_hmac('sha256', $request->getContent(), $privateKey) !== $sha256) {
                throw HttpException::fromStatusCode(
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    'Invalid checksum'
                );
            }

            /** @var PartialEntity|null $transaction */
            $transaction = $order->get('transactions')?->first();
            if (!$transaction) {
                throw HttpException::fromStatusCode(
                    Response::HTTP_BAD_REQUEST,
                    'Invalid Quickpay transaction'
                );
            }

            $context->state(function (Context $context) use (
                $request,
                $order,
                $transaction,
                $currentOperation,
                $operations
            ): void {
                $type = $currentOperation['type'] ?? null;
                if ($type === 'authorize') {
                    $this->stateTransition(
                        entity: $transaction,
                        entityName: OrderTransactionDefinition::ENTITY_NAME,
                        transitionName: OrderTransactionStates::STATE_AUTHORIZED,
                        context: $context
                    );
                    $this->stateTransition(
                        entity: $order,
                        entityName: OrderDefinition::ENTITY_NAME,
                        transitionName: OrderStates::STATE_IN_PROGRESS,
                        context: $context
                    );
                } elseif ($type === 'capture') {
                    $authorized = array_reduce($operations, function (int $carry, array $operation) {
                        if ((int)($operation['qp_status_code'] ?? 0) !== 20000) {
                            return $carry;
                        }

                        if (($operation['type'] ?? null) === 'authorize') {
                            return $carry + (int)($operation['amount'] ?? 0);
                        }

                        return $carry;
                    }, 0);

                    $isPaid = $request->request->getInt('balance') === $authorized;
                    $state = $isPaid ?
                        OrderTransactionStates::STATE_PAID :
                        OrderTransactionStates::STATE_PARTIALLY_PAID;

                    $this->stateTransition(
                        entity: $transaction,
                        entityName: OrderTransactionDefinition::ENTITY_NAME,
                        transitionName: $state,
                        context: $context
                    );

                    if ($isPaid) {
                        $this->stateTransition(
                            entity: $order,
                            entityName: OrderDefinition::ENTITY_NAME,
                            transitionName: OrderStates::STATE_COMPLETED,
                            context: $context
                        );

                        $updateShipping = $this->configService->get(
                            'WexoQuickpay.config.quickpayUpdateShipping',
                            $order->get('salesChannelId')
                        );
                        /** @var PartialEntity|null $delivery */
                        $delivery = $order->get('deliveries')?->first();

                        if ($updateShipping && $delivery) {
                            $this->stateTransition(
                                entity: $delivery,
                                entityName: OrderDeliveryDefinition::ENTITY_NAME,
                                transitionName: OrderDeliveryStates::STATE_SHIPPED,
                                context: $context
                            );
                        }
                    }
                } elseif ($type === 'refund') {
                    $state = $request->request->getInt('balance') === 0 ?
                        OrderTransactionStates::STATE_REFUNDED :
                        OrderTransactionStates::STATE_PARTIALLY_REFUNDED;

                    $this->stateTransition(
                        entity: $transaction,
                        entityName: OrderTransactionDefinition::ENTITY_NAME,
                        transitionName: $state,
                        context: $context
                    );
                } elseif ($type === 'cancel') {
                    $this->stateTransition(
                        entity: $transaction,
                        entityName: OrderTransactionDefinition::ENTITY_NAME,
                        transitionName: OrderTransactionStates::STATE_CANCELLED,
                        context: $context
                    );
                    $this->stateTransition(
                        entity: $order,
                        entityName: OrderDefinition::ENTITY_NAME,
                        transitionName: OrderStates::STATE_CANCELLED,
                        context: $context
                    );
                }
            }, SetOrderStateAction::FORCE_TRANSITION);

            return new Response(null, Response::HTTP_NO_CONTENT);
        }, 'Quickpay');
    }

    protected function stateTransition(
        PartialEntity $entity,
        string $entityName,
        string $transitionName,
        Context $context
    ): void {
        /** @var PartialEntity|null $state */
        $state = $entity->get('stateMachineState');

        if ($state?->get('technicalName') !== $transitionName) {
            $this->stateMachineRegistry->transition(
                transition: new Transition(
                    entityName: $entityName,
                    entityId: $entity->getId(),
                    transitionName: $transitionName,
                    stateFieldName: 'stateId'
                ),
                context: $context
            );
        }
    }
}
