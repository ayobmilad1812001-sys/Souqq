<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * Multi-tenant isolation for the catalogue (PRD section 2).
 *
 * The rule that matters: a seller may only ever touch rows where
 * products.seller_id === their own id. It is expressed once, here, and every
 * write endpoint routes through it -- there is no second place to forget it.
 */
final class ProductPolicy
{
    /**
     * Runs before every other check. Admins have global reach by design, so
     * short-circuiting here keeps the individual methods focused on the
     * seller/customer rules.
     */
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    /** The catalogue is public; listing is not gated. */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Product $product): bool
    {
        // Inactive products stay visible to their own seller so they can be
        // edited back into the catalogue, but are hidden from shoppers.
        if ($product->is_active) {
            return true;
        }

        return $user !== null && $this->owns($user, $product);
    }

    public function create(User $user): bool
    {
        return $user->role->canSell();
    }

    public function update(User $user, Product $product): bool
    {
        return $this->owns($user, $product);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->owns($user, $product);
    }

    private function owns(User $user, Product $product): bool
    {
        return $user->role->canSell() && $product->seller_id === $user->id;
    }
}
