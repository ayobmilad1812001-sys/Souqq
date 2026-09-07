<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CartItem;
use App\Models\User;

/**
 * A cart line belongs to exactly one shopper. Note the deliberate absence of an
 * admin bypass: nobody, including staff, edits someone elses basket.
 */
final class CartItemPolicy
{
    public function update(User $user, CartItem $item): bool
    {
        return $this->owns($user, $item);
    }

    public function delete(User $user, CartItem $item): bool
    {
        return $this->owns($user, $item);
    }

    private function owns(User $user, CartItem $item): bool
    {
        return $item->cart->user_id === $user->id;
    }
}
