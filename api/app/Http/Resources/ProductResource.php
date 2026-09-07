<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Product */
final class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            // Emitted as a string, exactly as stored. Casting to float here
            // would undo the whole point of the DECIMAL column.
            'price' => (string) $this->price,
            'sku' => $this->sku,
            'stock_quantity' => $this->stock_quantity,
            'in_stock' => $this->stock_quantity > 0,
            'is_active' => $this->is_active,

            // whenLoaded keeps the payload honest: a relation that was not
            // eager loaded is omitted rather than lazily fetched per row.
            'category' => CategoryResource::make($this->whenLoaded('category')),
            'seller' => $this->whenLoaded('seller', fn (): array => [
                'id' => $this->seller->id,
                'name' => $this->seller->name,
            ]),

            'reviews_count' => $this->whenCounted('reviews'),
            // Read through getAttributes() rather than the magic property:
            // strict mode throws on accessing an attribute that was never
            // selected, and withAvg() is only applied on the detail endpoint.
            'average_rating' => $this->when(
                ($average = $this->resource->getAttributes()['reviews_avg_rating'] ?? null) !== null,
                fn (): string => number_format((float) $average, 1)
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
