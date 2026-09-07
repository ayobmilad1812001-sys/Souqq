<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\OrderItem */
final class OrderItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'quantity' => $this->quantity,
            // The historical price, not the current catalogue price.
            'unit_price' => (string) $this->unit_price,
            'subtotal' => (string) $this->subtotal,
            'product' => ProductResource::make($this->whenLoaded('product')),
        ];
    }
}
