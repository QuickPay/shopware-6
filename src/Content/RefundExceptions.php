<?php declare(strict_types=1);

namespace Wexo\Quickpay\Content;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

class RefundExceptions extends HttpException
{
    public static function invalidAmount(float $amount): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            'QUICKPAY__REFUND_INVALID_AMOUNT',
            'Invalid amount ({{ $amount }}). Must be positive',
            ['amount' => $amount]
        );
    }

    public static function orderNotFound(?OrderEntity $order): self
    {
        if (!$order) {
            return new self(
                Response::HTTP_BAD_REQUEST,
                'QUICKPAY__REFUND_ORDER_NOT_FOUND',
                'Order not found'
            );
        }

        return new self(
            Response::HTTP_BAD_REQUEST,
            'QUICKPAY__REFUND_ORDER_NOT_FOUND',
            'Missing Quickpay response'
        );
    }

    public static function invalidResponse(): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            'QUICKPAY__REFUND_INVALID_RESPONSE',
            'Invalid response'
        );
    }

    public static function invalidOrder(string $orderId): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            'QUICKPAY__REFUND_INVALID_RESPONSE',
            'Invalid Quickpay order with id: {{ orderId }}',
            ['orderId' => $orderId]
        );
    }

    public static function refundAmountToLarge(float $amount, float $currentBalance): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            'QUICKPAY__REFUND_AMOUNT_TO_LARGE',
            'Amount {{ amount }} must be less than or equal to {{ currentBalance }}',
            ['amount' => $amount, 'currentBalance' => $currentBalance]
        );
    }
}
