<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Carries both ends of the transition so listeners can react to a specific
 * edge (for example "shipped -> delivered") rather than re-deriving it.
 */
final class OrderStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $from,
        public readonly OrderStatus $to,
    ) {}

    public function isShipment(): bool
    {
        return $this->to === OrderStatus::Shipped;
    }

    public function isCancellation(): bool
    {
        return $this->to === OrderStatus::Cancelled;
    }
}
