<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Jobs\SendOrderConfirmationJob;

/**
 * Bridges the domain event to the queue. The listener itself stays synchronous
 * and trivial -- all it does is enqueue -- so a broker hiccup surfaces as a
 * failed job rather than as a failed checkout.
 */
final class SendOrderConfirmation
{
    public function handle(OrderCreated $event): void
    {
        SendOrderConfirmationJob::dispatch($event->order->id);
    }
}
