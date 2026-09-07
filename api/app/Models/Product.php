<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $seller_id
 * @property int $category_id
 * @property string $name
 * @property string $description
 * @property string $price      DECIMAL(10,2) surfaced as a string, never a float.
 * @property string $sku
 * @property int $stock_quantity
 * @property bool $is_active
 */
class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;

    /** Escape character used by the LIKE-based catalogue search. */
    private const LIKE_ESCAPE = '!';

    /** @var list<string> */
    protected $fillable = [
        'seller_id',
        'category_id',
        'name',
        'description',
        'price',
        'sku',
        'stock_quantity',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // decimal:2 keeps the value as a formatted *string*. Casting to
            // float here would reintroduce exactly the precision loss the
            // DECIMAL column exists to prevent.
            'price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** The price as an exact value object suitable for arithmetic. */
    public function priceAsMoney(): Money
    {
        return Money::of((string) $this->price);
    }

    public function hasStockFor(int $quantity): bool
    {
        return $this->stock_quantity >= $quantity;
    }

    public function isPurchasable(): bool
    {
        return $this->is_active && $this->stock_quantity > 0;
    }

    // ---------------------------------------------------------------------
    // Query scopes: the catalogue filters from the PRD live here so the
    // controller stays a thin translation layer over validated input.
    // ---------------------------------------------------------------------

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<self>  $query */
    public function scopeOwnedBy(Builder $query, int $sellerId): void
    {
        $query->where('seller_id', $sellerId);
    }

    /**
     * Keyword search across name and description.
     *
     * NOTE: LIKE with a leading wildcard cannot use the B-tree index. It is
     * correct and fine at this scale; swap in a MySQL FULLTEXT index (or a
     * dedicated search engine) once the catalogue outgrows it.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        // Neutralise LIKE wildcards so a shopper searching for "50%" matches
        // the literal text rather than every row in the table.
        //
        // The escape character is "!" rather than the more usual backslash:
        // an explicit ESCAPE clause is required for the escaping to mean
        // anything at all, and "!" is an unambiguous single-character literal
        // on both MySQL and SQLite, whereas a backslash literal is interpreted
        // differently by each of them.
        $escaped = str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
            $term
        );

        $pattern = "%{$escaped}%";

        $query->where(function (Builder $inner) use ($pattern): void {
            $inner->whereRaw("name LIKE ? ESCAPE '".self::LIKE_ESCAPE."'", [$pattern])
                ->orWhereRaw("description LIKE ? ESCAPE '".self::LIKE_ESCAPE."'", [$pattern]);
        });
    }

    /** @param  Builder<self>  $query */
    public function scopeInCategory(Builder $query, int|string|null $category): void
    {
        if (blank($category)) {
            return;
        }

        // Accept either a numeric id or a slug, so clients can use whichever
        // identifier they already hold.
        if (is_numeric($category)) {
            $query->where('category_id', (int) $category);

            return;
        }

        $query->whereHas('category', fn (Builder $inner) => $inner->where('slug', $category));
    }

    /** @param  Builder<self>  $query */
    public function scopePriceBetween(Builder $query, ?string $min, ?string $max): void
    {
        if (filled($min)) {
            $query->where('price', '>=', Money::of($min)->toDecimalString());
        }

        if (filled($max)) {
            $query->where('price', '<=', Money::of($max)->toDecimalString());
        }
    }

    /**
     * Whitelisted sorting. Anything unrecognised falls back to newest first,
     * which keeps the ORDER BY clause free of user-controlled column names.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSorted(Builder $query, ?string $sort): void
    {
        match ($sort) {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'name_asc' => $query->orderBy('name'),
            'name_desc' => $query->orderByDesc('name'),
            'oldest' => $query->orderBy('created_at'),
            default => $query->orderByDesc('created_at'),
        };

        // Deterministic tiebreaker: without it, rows sharing a price can appear
        // on two consecutive pages (or on neither).
        $query->orderBy('id');
    }
}
