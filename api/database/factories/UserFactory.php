<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\User>
 */
final class UserFactory extends Factory
{
    /** Hashed once and reused: bcrypt is deliberately slow, and a suite that
     * creates hundreds of users would otherwise spend most of its time hashing. */
    private static ?string $passwordHash = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$passwordHash ??= Hash::make('password'),
            'role' => UserRole::Customer,
            'remember_token' => Str::random(10),
        ];
    }

    public function customer(): static
    {
        return $this->state(fn (): array => ['role' => UserRole::Customer]);
    }

    public function seller(): static
    {
        return $this->state(fn (): array => ['role' => UserRole::Seller]);
    }

    public function admin(): static
    {
        return $this->state(fn (): array => ['role' => UserRole::Admin]);
    }
}
