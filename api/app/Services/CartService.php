<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\ProductUnavailableException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * All cart mutations. Every method returns a cart with its items and products
 * eager loaded, so callers never trigger N+1 queries when serialising.
 */
final readonly class CartService
{
    /**
     * Fetch the customers single active cart, creating it on first use.
     *
     * firstOrCreate races with itself under concurrent requests, but the unique
     * index on carts.user_id turns that race into a duplicate-key error rather
     * than two carts; we retry once to resolve it.
     */
    public function forUser(User $user): Cart
    {
        try {
            $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $cart = Cart::query()->where('user_id', $user->id)->firstOrFail();
        }

        return $this->withRelations($cart);
    }

    /**
     * Add a product, or increase the quantity if it is already in the cart.
     *
     * Stock is validated here for fast feedback, but this check is advisory
     * only: the authoritative check happens under a row lock at checkout, since
     * stock can change between browsing and paying.
     */
    public function addItem(User $user, Product $product, int $quantity): Cart
    {
        $cart = $this->forUser($user);

        $existing = $cart->items->firstWhere('product_id', $product->id);
        $desired = ($existing?->quantity ?? 0) + $quantity;

        $this->assertPurchasable($product, $desired);

        DB::transaction(function () use ($cart, $product, $desired): void {
            CartItem::query()->updateOrCreate(
                ['cart_id' => $cart->id, 'product_id' => $product->id],
                ['quantity' => $desired],
            );

            // Touch the cart so "abandoned cart" tooling can rely on updated_at.
            $cart->touch();
        });

        return $this->withRelations($cart->fresh());
    }

    /** Set an absolute quantity for a line the caller already owns. */
    public function updateItem(CartItem $item, int $quantity): Cart
    {
        $this->assertPurchasable($item->product, $quantity);

        $item->update(['quantity' => $quantity]);
        $item->cart->touch();

        return $this->withRelations($item->cart->fresh());
    }

    public function removeItem(CartItem $item): Cart
    {
        $cart = $item->cart;
        $item->delete();
        $cart->touch();

        return $this->withRelations($cart->fresh());
    }

    /**
     * Empty the cart. Called at the end of a successful checkout from inside
     * the order transaction, so a failed order leaves the cart untouched.
     */
    public function clear(Cart $cart): void
    {
        $cart->items()->delete();
        $cart->touch();
    }

    /**
     * @throws ProductUnavailableException
     * @throws InsufficientStockException
     */
    private function assertPurchasable(Product $product, int $quantity): void
    {
        if (! $product->is_active) {
            throw new ProductUnavailableException($product);
        }

        if (! $product->hasStockFor($quantity)) {
            throw new InsufficientStockException($product, $quantity, $product->stock_quantity);
        }
    }

    private function withRelations(Cart $cart): Cart
    {
        return $cart->load(['items.product.category']);
    }
}
