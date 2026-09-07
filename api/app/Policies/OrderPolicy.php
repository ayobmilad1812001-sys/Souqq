<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;

/**
 * Three actors, three different views of the same order:
 *
 *  - the customer who placed it may read it and cancel it early;
 *  - a seller may read it only if it contains one of their products, and may
 *    advance it through fulfilment;
 *  - an admin may do anything.
 */
final class OrderPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        // Everyone authenticated can list orders; the *query* is scoped by role
        // in the controller so a seller never sees another sellers rows.
        return true;
    }

    public function view(User $user, Order $order): bool
    {
        if ($order->user_id === $user->id) {
            return true;
        }

        return $user->isSeller() && $order->belongsToSeller($user->id);
    }

    /**
     * Fulfilment transitions. Customers do not use this path at all -- their
     * only lifecycle action is cancel(), which has its own time window.
     */
    public function updateStatus(User $user, Order $order, OrderStatus $target): bool
    {
        if (! $user->isSeller()) {
            return false;
        }

        if (! $order->belongsToSeller($user->id)) {
            return false;
        }

        // A seller may move an order forward through fulfilment, but only the
        // platform may cancel one that is already being processed -- otherwise
        // one seller could cancel an order containing another sellers goods.
        return $target !== OrderStatus::Cancelled;
    }

    public function cancel(User $user, Order $order): bool
    {
        return $order->user_id === $user->id;
    }
}
