<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property OrderStatus $status
 * @property string $subtotal
 * @property string $shipping_cost
 * @property string $total
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Database\Eloquent\Collection<int, OrderItem> $items
 */
class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'status',
        'subtotal',
        'shipping_cost',
        'total',
        'cancelled_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function totalAsMoney(): Money
    {
        return Money::of((string) $this->total);
    }

    /**
     * Whether the customer who placed this order may still cancel it.
     *
     * Two independent gates: the status must be an early one, and the request
     * must fall inside the configured cancellation window. Sellers/admins use
     * the status-transition path instead, which is not time limited.
     */
    public function isWithinCancellationWindow(): bool
    {
        $minutes = (int) config('marketplace.orders.cancellation_window_minutes');

        return $this->created_at->diffInMinutes(now()) <= $minutes;
    }

    /** Does this order contain at least one product owned by the given seller? */
    public function belongsToSeller(int $sellerId): bool
    {
        return $this->items()
            ->whereHas('product', fn (Builder $query) => $query->where('seller_id', $sellerId))
            ->exists();
    }

    /** @param  Builder<self>  $query */
    public function scopeStatus(Builder $query, ?string $status): void
    {
        if (filled($status)) {
            $query->where('status', $status);
        }
    }

    /**
     * Restricts a listing to orders containing the sellers products. Used by
     * the seller order dashboard so multi-tenant isolation is applied at the
     * query level rather than filtered in PHP after the fact.
     *
     * @param  Builder<self>  $query
     */
    public function scopeContainingProductsOf(Builder $query, int $sellerId): void
    {
        $query->whereHas(
            'items.product',
            fn (Builder $inner) => $inner->where('seller_id', $sellerId)
        );
    }
}
