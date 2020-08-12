<?php declare(strict_types=1);

namespace Wexo\Quickpay\Subscriber;

use Error;
use Monolog\Logger;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use stdClass;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Wexo\Quickpay\Service\QuickpayPayment;
use Wexo\Quickpay\WexoQuickpay;

/**
 * Class OrderDetailSubscriber
 * @package Wexo\Quickpay\Subscriber
 */
class OrderDetailSubscriber implements EventSubscriberInterface
{
    protected EntityRepositoryInterface $orderRepository;
    protected SystemConfigService $systemConfigService;
    protected QuickpayPayment $quickpayPaymentService;

    /**
     * OrderDetailSubscriber constructor.
     * @param EntityRepositoryInterface $orderRepository
     * @param SystemConfigService $systemConfigService
     * @param QuickpayPayment $quickpayPaymentService
     */
    public function __construct(
        EntityRepositoryInterface $orderRepository,
        SystemConfigService $systemConfigService,
        QuickpayPayment $quickpayPaymentService
    ) {
        $this->orderRepository = $orderRepository;
        $this->systemConfigService = $systemConfigService;
        $this->quickpayPaymentService = $quickpayPaymentService;
    }

    /**
     * @return array|string[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            StateMachineTransitionEvent::class => 'onStateMachineTransitionEvent'
        ];
    }

    /**
     * @param StateMachineTransitionEvent $event
     */
    public function onStateMachineTransitionEvent(StateMachineTransitionEvent $event)
    {
        $context = Context::createDefaultContext();
        $eventName = $event->getToPlace()->getTechnicalName();
        if ($eventName == "completed") {
            try {
                $orderId = $event->getEntityId();

                if (!$orderId) {
                    return;
                }

                /** @var OrderEntity $order */
                $order = $this->orderRepository->search(
                    new Criteria([$orderId]),
                    $context
                )->first();

                // TODO: Send emails to shop admin on payment error

                if (!$order) {
                    $this->quickpayPaymentService->paymentLogger(
                        WexoQuickpay::ORDER_COMPLETE_ERROR,
                        [
                            'error' => 'Order with ID ' . $orderId . ' could no be found'
                        ]
                    );

                    return;
                }

                $customFields = $order->getCustomFields();
                if (!isset($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD])) {
                    $this->quickpayPaymentService->paymentLogger(
                        WexoQuickpay::ORDER_COMPLETE_ERROR,
                        [
                            'error' => 'QuickPay response could not be found on order',
                            'orderId' => $orderId,
                            'orderNumber' => $order->getOrderNumber() ?? null,
                            'customFields' => $customFields
                        ]
                    );

                    return;
                }

                /** @var stdClass $paymentResponse */
                $paymentResponse = json_decode($customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD]);

                if (!$paymentResponse
                    || !property_exists($paymentResponse, 'id')
                    || !property_exists($paymentResponse, 'order_id')
                ) {
                    $this->quickpayPaymentService->paymentLogger(
                        WexoQuickpay::ORDER_COMPLETE_ERROR,
                        [
                            'error' => 'QuickPay ID or order ID could not be found in the orders QuickPay response',
                            'orderId' => $orderId,
                            'orderNumber' => $order->getOrderNumber() ?? null,
                            'paymentResponse' => $paymentResponse ?? null
                        ]
                    );

                    return;
                }

                // Ensure it's the correct order we're trying to capture
                if ($paymentResponse->order_id != $order->getOrderNumber()) {
                    return;
                }

                $response = $this->quickpayPaymentService->http->request(
                    'POST',
                    'payments/' . $paymentResponse->id . '/capture',
                    [
                        'form_params' => [
                            'amount' => $order->getPrice()->getTotalPrice() * 100
                        ]
                    ]
                );

                $statusCode = $response->getStatusCode();
                $responseBody = $response->getBody()->getContents();
                $logEntry = [
                    'orderId' => $orderId,
                    'orderNumber' => $order->getOrderNumber() ?? null,
                    'paymentId' => $paymentResponse->id,
                    'responseStatusCode' => $statusCode,
                    'response' => json_decode($responseBody)
                ];
                if ($statusCode === 202) {
                    $this->quickpayPaymentService->paymentLogger(
                        WexoQuickpay::ORDER_COMPLETE_SUCCESS,
                        $logEntry,
                        Logger::INFO
                    );

                    $customFields[WexoQuickpay::QUICKPAY_RESPONSE_FIELD] = $responseBody;

                    $this->orderRepository->update(
                        [
                            [
                                'id' => $orderId,
                                'customFields' => $customFields
                            ]
                        ],
                        Context::createDefaultContext()
                    );
                } else {
                    $this->quickpayPaymentService->paymentLogger(
                        WexoQuickpay::ORDER_COMPLETE_ERROR,
                        $logEntry
                    );
                }
            } catch (Error | \TypeError | \Exception $e) {
                $this->quickpayPaymentService->paymentLogger(
                    WexoQuickpay::ORDER_COMPLETE_ERROR,
                    [
                        'orderId' => $orderId ?? null,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'errorType' => get_class($e)
                    ]
                );
            }
        }
    }
}
