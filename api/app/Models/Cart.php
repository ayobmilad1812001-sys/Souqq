<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property \Illuminate\Database\Eloquent\Collection<int, CartItem> $items
 */
class Cart extends Model
{
    /** @use HasFactory<\Database\Factories\CartFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Live subtotal of the cart at current catalogue prices.
     *
     * Assumes `items.product` is eager loaded; the service layer always loads
     * it, which keeps this free of N+1 queries.
     */
    public function subtotal(): Money
    {
        return Money::sum($this->items->map(
            static fn (CartItem $item): Money => $item->lineTotal()
        ));
    }

    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    public function totalQuantity(): int
    {
        return (int) $this->items->sum('quantity');
    }
}
