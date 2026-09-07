<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once an order has been committed to the database.
 *
 * Everything that is not part of *making the sale* hangs off this event --
 * confirmation email, seller notification, analytics. Adding another side
 * effect later means adding a listener, not editing OrderService.
 */
final class OrderCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
