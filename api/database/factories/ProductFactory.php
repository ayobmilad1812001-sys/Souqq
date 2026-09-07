<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\Product>
 */
final class ProductFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'seller_id' => User::factory()->seller(),
            'category_id' => Category::factory(),
            'name' => Str::title(fake()->words(3, true)),
            'description' => fake()->paragraph(),
            // Generated as a formatted string, never as a float, so tests
            // exercise the same representation production stores.
            'price' => number_format(fake()->randomFloat(2, 5, 2000), 2, '.', ''),
            'sku' => Str::upper(Str::random(10)),
            'stock_quantity' => fake()->numberBetween(1, 200),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (): array => ['stock_quantity' => 0]);
    }

    public function priced(string $price): static
    {
        return $this->state(fn (): array => ['price' => $price]);
    }

    public function withStock(int $quantity): static
    {
        return $this->state(fn (): array => ['stock_quantity' => $quantity]);
    }
}
