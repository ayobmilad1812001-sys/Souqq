<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Order;
use Symfony\Component\HttpFoundation\Response;

final class OrderNotCancellableException extends DomainException
{
    public function __construct(Order $order, string $reason)
    {
        $this->context = [
            'order_id' => $order->id,
            'status' => $order->status->value,
            'reason' => $reason,
        ];

        parent::__construct("Order #{$order->id} can no longer be cancelled: {$reason}");
    }

    public function status(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
