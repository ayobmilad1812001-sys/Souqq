<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Order */
final class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            // Exposing the legal next states saves clients from hard-coding the
            // state machine and drifting out of sync with the server.
            'allowed_transitions' => array_map(
                static fn ($status): string => $status->value,
                $this->status->allowedTransitions()
            ),
            'subtotal' => (string) $this->subtotal,
            'shipping_cost' => (string) $this->shipping_cost,
            'total' => (string) $this->total,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
            'customer' => UserResource::make($this->whenLoaded('user')),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
