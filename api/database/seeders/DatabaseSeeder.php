<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds a demo marketplace: one admin, a handful of sellers, a realistic
 * catalogue, and customers you can log in as immediately.
 *
 * All demo accounts use the password: password
 */
final class DatabaseSeeder extends Seeder
{
    private const CATEGORIES = [
        'Electronics',
        'Home & Kitchen',
        'Fashion',
        'Beauty',
        'Sports & Outdoors',
        'Books',
    ];

    public function run(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Platform Admin',
            'email' => 'admin@libyamarket.test',
        ]);

        $customer = User::factory()->customer()->create([
            'name' => 'Demo Customer',
            'email' => 'customer@libyamarket.test',
        ]);

        $sellers = User::factory()->seller()->count(5)->create();

        User::factory()->customer()->count(20)->create();

        $categories = collect(self::CATEGORIES)->map(
            fn (string $name): Category => Category::query()->create([
                'name' => $name,
                'slug' => Str::slug($name),
            ])
        );

        // A spread of stock levels so the seeded data exercises the
        // out-of-stock and low-stock paths, not just the happy one.
        $sellers->each(function (User $seller) use ($categories): void {
            Product::factory()
                ->count(12)
                ->recycle($categories)
                ->create(['seller_id' => $seller->id]);

            Product::factory()
                ->count(2)
                ->outOfStock()
                ->recycle($categories)
                ->create(['seller_id' => $seller->id]);

            Product::factory()
                ->count(1)
                ->inactive()
                ->recycle($categories)
                ->create(['seller_id' => $seller->id]);
        });

        $this->command?->info('Seeded demo marketplace.');
        $this->command?->info("Admin:    {$admin->email} / password");
        $this->command?->info("Customer: {$customer->email} / password");
    }
}
