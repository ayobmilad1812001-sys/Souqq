<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Product;
use App\Models\Review;
use App\Models\User;

final class ReviewPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    /**
     * Only verified purchasers may review, and never their own product. This is
     * the single most effective guard against review spam.
     */
    public function create(User $user, Product $product): bool
    {
        if ($product->seller_id === $user->id) {
            return false;
        }

        return $user->orders()
            ->whereHas('items', fn ($query) => $query->where('product_id', $product->id))
            ->exists();
    }

    public function update(User $user, Review $review): bool
    {
        return $review->user_id === $user->id;
    }

    public function delete(User $user, Review $review): bool
    {
        return $review->user_id === $user->id;
    }
}
