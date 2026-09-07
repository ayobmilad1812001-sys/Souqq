<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\ProductUnavailableException;
use App\Jobs\RecordInventoryMovementJob;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Owns every write to products.stock_quantity.
 *
 * CONCURRENCY MODEL (see README, "Concurrency control")
 * -----------------------------------------------------
 * The race this class defends against is the classic lost update / oversell:
 *
 *   T1: SELECT stock_quantity -> 1
 *   T2: SELECT stock_quantity -> 1      (T1 has not written yet)
 *   T1: UPDATE ... SET stock_quantity = 0
 *   T2: UPDATE ... SET stock_quantity = 0
 *   => two customers bought the same single unit; stock never went negative,
 *      so nothing in the data hints that anything went wrong.
 *
 * Chosen mitigation: PESSIMISTIC locking via SELECT ... FOR UPDATE
 * (Eloquent lockForUpdate()). The lock is taken inside the surrounding order
 * transaction and held until commit/rollback, so T2 blocks on the SELECT until
 * T1 finishes and then re-reads the true remaining quantity.
 *
 * Why pessimistic rather than optimistic (version column + retry)?
 *  - Checkout contention on a hot SKU is high and bursty (flash sales, drops).
 *    Optimistic retries would thrash: most transactions would fail and replay
 *    the whole order build, amplifying load exactly when the system is busiest.
 *  - The critical section here is tiny (a handful of row reads plus updates) so
 *    lock hold time is short and the throughput cost is acceptable.
 *  - It composes naturally with the single order transaction the PRD requires.
 *
 * Deadlock avoidance: products are always locked in ascending id order. If two
 * carts contain the same two SKUs in different orders, a consistent global lock
 * ordering means they queue instead of deadlocking.
 */
final readonly class InventoryService
{
    /**
     * Lock the given products FOR UPDATE and return them keyed by id.
     *
     * MUST be called inside a transaction; outside one, MySQL releases the lock
     * immediately and the guarantee evaporates.
     *
     * @param  array<int, int>  $productIds
     * @return \Illuminate\Support\Collection<int, Product>
     */
    public function lockProducts(array $productIds): \Illuminate\Support\Collection
    {
        $ids = array_values(array_unique($productIds));
        // Deterministic global lock ordering -- see the deadlock note above.
        sort($ids);

        return Product::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Assert that a locked product can satisfy the requested quantity.
     *
     * @throws ProductUnavailableException
     * @throws InsufficientStockException
     */
    public function assertAvailable(Product $product, int $quantity): void
    {
        if (! $product->is_active) {
            throw new ProductUnavailableException($product);
        }

        if (! $product->hasStockFor($quantity)) {
            throw new InsufficientStockException($product, $quantity, $product->stock_quantity);
        }
    }

    /**
     * Decrement stock for a product already locked by lockProducts().
     *
     * The UPDATE is expressed as a conditional decrement rather than a blind
     * write of a value computed in PHP. Belt and braces: even if a future
     * refactor loses the lock, `WHERE stock_quantity >= ?` makes overselling
     * impossible -- the statement simply affects zero rows and we fail loudly.
     */
    public function decrement(Product $product, int $quantity, ?int $orderId = null): void
    {
        $affected = Product::query()
            ->whereKey($product->getKey())
            ->where('stock_quantity', '>=', $quantity)
            ->update([
                'stock_quantity' => DB::raw("stock_quantity - {$quantity}"),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            // Re-read to report the real remaining figure to the caller.
            $product->refresh();

            throw new InsufficientStockException($product, $quantity, $product->stock_quantity);
        }

        $product->stock_quantity -= $quantity;
        $product->syncOriginal();

        $this->recordMovement($product, -$quantity, InventoryMovement::REASON_SALE, $orderId);
    }

    /**
     * Return stock to the shelf when an order is cancelled before dispatch.
     */
    public function restock(Product $product, int $quantity, string $reason, ?int $orderId = null): void
    {
        Product::query()
            ->whereKey($product->getKey())
            ->update([
                'stock_quantity' => DB::raw("stock_quantity + {$quantity}"),
                'updated_at' => now(),
            ]);

        $product->stock_quantity += $quantity;
        $product->syncOriginal();

        $this->recordMovement($product, $quantity, $reason, $orderId);
    }

    /**
     * Ledger writes are pushed to the queue: they are audit data, not part of
     * the correctness of the sale, so they must not lengthen the critical
     * section or fail a checkout if the ledger table is slow.
     */
    private function recordMovement(Product $product, int $delta, string $reason, ?int $orderId): void
    {
        RecordInventoryMovementJob::dispatch(
            productId: $product->id,
            orderId: $orderId,
            reason: $reason,
            quantityDelta: $delta,
            resultingQuantity: $product->stock_quantity,
        );
    }
}
