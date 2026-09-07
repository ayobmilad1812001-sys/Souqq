<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_visitor_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Amina Saleh',
            'email' => 'amina@example.com',
            'password' => 'Str0ngPassword',
            'password_confirmation' => 'Str0ngPassword',
        ]);

        $response->assertCreated()
            ->assertJson(['success' => true, 'message' => 'Registration successful.'])
            ->assertJsonStructure(['success', 'data' => ['user' => ['id', 'name', 'email', 'role'], 'token'], 'message']);

        $this->assertDatabaseHas('users', [
            'email' => 'amina@example.com',
            'role' => UserRole::Customer->value,
        ]);
    }

    #[Test]
    public function registration_stores_a_hashed_password(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Amina Saleh',
            'email' => 'amina@example.com',
            'password' => 'Str0ngPassword',
            'password_confirmation' => 'Str0ngPassword',
        ])->assertCreated();

        $user = User::query()->where('email', 'amina@example.com')->sole();

        $this->assertNotSame('Str0ngPassword', $user->password);
        $this->assertTrue(Hash::check('Str0ngPassword', $user->password));
    }

    #[Test]
    public function a_visitor_may_register_as_a_seller(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Seller Co',
            'email' => 'seller@example.com',
            'password' => 'Str0ngPassword',
            'password_confirmation' => 'Str0ngPassword',
            'role' => 'seller',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'seller@example.com', 'role' => 'seller']);
    }

    /**
     * Privilege escalation guard: self-service registration must never be able
     * to mint an administrator.
     */
    #[Test]
    public function a_visitor_cannot_register_as_an_admin(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'Str0ngPassword',
            'password_confirmation' => 'Str0ngPassword',
            'role' => 'admin',
        ])->assertStatus(422)->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
    }

    #[Test]
    public function registration_validation_uses_the_documented_error_envelope(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Validation failed.'])
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    #[Test]
    public function registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/register', [
            'name' => 'Someone',
            'email' => 'taken@example.com',
            'password' => 'Str0ngPassword',
            'password_confirmation' => 'Str0ngPassword',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    #[Test]
    public function a_user_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);

        $this->postJson('/api/v1/login', [
            'email' => 'user@example.com',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['token']]);
    }

    #[Test]
    public function login_fails_with_a_wrong_password(): void
    {
        User::factory()->create(['email' => 'user@example.com']);

        $this->postJson('/api/v1/login', [
            'email' => 'user@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    #[Test]
    public function login_does_not_reveal_whether_an_account_exists(): void
    {
        User::factory()->create(['email' => 'real@example.com']);

        $known = $this->postJson('/api/v1/login', ['email' => 'real@example.com', 'password' => 'nope']);
        $unknown = $this->postJson('/api/v1/login', ['email' => 'ghost@example.com', 'password' => 'nope']);

        $this->assertSame(
            $known->json('errors.email'),
            $unknown->json('errors.email'),
            'Login must return an identical message for unknown users and wrong passwords.'
        );
    }

    #[Test]
    public function an_authenticated_user_can_read_their_profile(): void
    {
        $user = User::factory()->seller()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role', 'seller');
    }

    #[Test]
    public function guests_are_rejected_with_the_error_envelope(): void
    {
        $this->getJson('/api/v1/user')
            ->assertUnauthorized()
            ->assertExactJson(['success' => false, 'message' => 'Unauthenticated.']);
    }

    #[Test]
    public function logging_out_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $keep = $user->createToken('laptop')->plainTextToken;
        $revoke = $user->createToken('phone')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$revoke}")
            ->postJson('/api/v1/logout')
            ->assertOk();

        $this->assertSame(1, $user->tokens()->count());

        $this->withHeader('Authorization', "Bearer {$keep}")
            ->getJson('/api/v1/user')
            ->assertOk();
    }
}
