<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderStatusChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Audit trail for lifecycle changes, kept off the request thread.
 */
final class LogOrderStatusChange implements ShouldQueue
{
    public function handle(OrderStatusChanged $event): void
    {
        Log::info('Order status changed.', [
            'order_id' => $event->order->id,
            'from' => $event->from->value,
            'to' => $event->to->value,
        ]);
    }
}
