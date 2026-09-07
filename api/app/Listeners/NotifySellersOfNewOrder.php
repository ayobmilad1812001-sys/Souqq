<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Fans an order out to the sellers whose products it contains.
 *
 * Queued, because one order can span many sellers and none of that work belongs
 * in the checkout request. Logging stands in for the real notification channel
 * (push/SMS/webhook) that a production deployment would wire up here.
 */
final class NotifySellersOfNewOrder implements ShouldQueue
{
    public function handle(OrderCreated $event): void
    {
        $event->order->loadMissing('items.product');

        $sellerIds = $event->order->items
            ->pluck('product.seller_id')
            ->unique()
            ->values();

        foreach ($sellerIds as $sellerId) {
            Log::info('New order awaiting fulfilment.', [
                'order_id' => $event->order->id,
                'seller_id' => $sellerId,
            ]);
        }
    }
}
