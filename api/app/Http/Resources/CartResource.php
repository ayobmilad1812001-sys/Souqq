<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Cart */
final class CartResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $subtotal = $this->subtotal();
        // Show the shipping the shopper *would* pay, so the cart screen can
        // display "spend X more for free delivery" without a second endpoint.
        $pricing = app(PricingService::class);
        $shipping = $pricing->shippingCost($subtotal);

        return [
            'id' => $this->id,
            'items' => CartItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->items->count(),
            'total_quantity' => $this->totalQuantity(),
            'subtotal' => $subtotal->toDecimalString(),
            'estimated_shipping' => $shipping->toDecimalString(),
            'estimated_total' => $pricing->total($subtotal, $shipping)->toDecimalString(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
