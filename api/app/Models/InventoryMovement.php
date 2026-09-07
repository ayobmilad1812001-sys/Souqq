<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only stock ledger. Rows are written by a queued job, never updated.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $order_id
 * @property string $reason
 * @property int $quantity_delta
 * @property int $resulting_quantity
 */
class InventoryMovement extends Model
{
    public const REASON_SALE = 'sale';

    public const REASON_CANCELLATION = 'cancellation';

    public const REASON_ADJUSTMENT = 'adjustment';

    /** @var list<string> */
    protected $fillable = [
        'product_id',
        'order_id',
        'reason',
        'quantity_delta',
        'resulting_quantity',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity_delta' => 'integer',
            'resulting_quantity' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
