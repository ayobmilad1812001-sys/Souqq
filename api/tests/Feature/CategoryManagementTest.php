<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    #[Test]
    public function anyone_can_list_categories(): void
    {
        Category::factory()->count(3)->create();

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'products_count']]]);
    }

    #[Test]
    public function an_admin_can_create_a_category(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/categories', ['name' => 'Home & Kitchen'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Home & Kitchen')
            ->assertJsonPath('data.slug', 'home-kitchen');

        $this->assertDatabaseHas('categories', ['slug' => 'home-kitchen']);
    }

    #[Test]
    public function slugs_are_made_unique_automatically(): void
    {
        Category::factory()->create(['name' => 'Books', 'slug' => 'books']);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/categories', ['name' => 'Books'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'books-2');
    }

    #[Test]
    public function a_seller_cannot_manage_the_taxonomy(): void
    {
        $this->actingAs(User::factory()->seller()->create())
            ->postJson('/api/v1/categories', ['name' => 'Sneaky Category'])
            ->assertForbidden();

        $this->assertDatabaseMissing('categories', ['name' => 'Sneaky Category']);
    }

    #[Test]
    public function a_customer_cannot_manage_the_taxonomy(): void
    {
        $category = Category::factory()->create();

        $this->actingAs(User::factory()->customer()->create())
            ->patchJson("/api/v1/categories/{$category->id}", ['name' => 'Hijacked'])
            ->assertForbidden();
    }

    #[Test]
    public function a_guest_cannot_manage_the_taxonomy(): void
    {
        $this->postJson('/api/v1/categories', ['name' => 'Anonymous'])->assertUnauthorized();
    }

    #[Test]
    public function category_creation_is_validated(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/categories', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    #[Test]
    public function an_admin_can_rename_a_category(): void
    {
        $category = Category::factory()->create(['name' => 'Electronic', 'slug' => 'electronic']);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/categories/{$category->id}", ['name' => 'Electronics'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Electronics')
            // The slug is deliberately left alone so existing links keep working.
            ->assertJsonPath('data.slug', 'electronic');
    }

    #[Test]
    public function an_admin_can_delete_an_empty_category(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/categories/{$category->id}")
            ->assertOk();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    /**
     * Deleting a category that still holds products would orphan listings, so
     * the API refuses with a conflict rather than cascading the delete.
     */
    #[Test]
    public function a_category_with_products_cannot_be_deleted(): void
    {
        $category = Category::factory()->create();
        Product::factory()->create(['category_id' => $category->id]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/categories/{$category->id}")
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }
}
