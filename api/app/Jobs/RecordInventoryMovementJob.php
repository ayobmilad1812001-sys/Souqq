<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\InventoryMovement;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Appends one row to the stock ledger.
 *
 * Deliberately queued: the ledger is audit data, so writing it must not extend
 * the row-lock hold time inside checkout, where every millisecond is contended.
 */
final class RecordInventoryMovementJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $productId,
        public readonly ?int $orderId,
        public readonly string $reason,
        public readonly int $quantityDelta,
        public readonly int $resultingQuantity,
    ) {}

    public function handle(): void
    {
        InventoryMovement::query()->create([
            'product_id' => $this->productId,
            'order_id' => $this->orderId,
            'reason' => $this->reason,
            'quantity_delta' => $this->quantityDelta,
            'resulting_quantity' => $this->resultingQuantity,
        ]);
    }
}
