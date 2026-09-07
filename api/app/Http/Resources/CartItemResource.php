<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CartItem */
final class CartItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity' => $this->quantity,
            'unit_price' => (string) $this->product->price,
            // Priced live: a cart reflects todays price, an order freezes it.
            'line_total' => $this->lineTotal()->toDecimalString(),
            'product' => ProductResource::make($this->whenLoaded('product')),
        ];
    }
}
