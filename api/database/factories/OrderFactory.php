<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Order>
 */
final class OrderFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $subtotal = number_format(fake()->randomFloat(2, 20, 3000), 2, '.', '');
        $shipping = '15.00';

        return [
            'user_id' => User::factory()->customer(),
            'status' => OrderStatus::Pending,
            'subtotal' => $subtotal,
            'shipping_cost' => $shipping,
            'total' => number_format((float) $subtotal + (float) $shipping, 2, '.', ''),
        ];
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
