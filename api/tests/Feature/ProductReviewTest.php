<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProductReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->customer()->create();
        $this->product = Product::factory()->create();
    }

    #[Test]
    public function anyone_can_read_the_reviews_of_a_product(): void
    {
        Review::factory()->count(2)->create(['product_id' => $this->product->id]);

        $this->getJson("/api/v1/products/{$this->product->id}/reviews")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'rating', 'comment', 'author']]]);
    }

    #[Test]
    public function a_verified_purchaser_can_review_a_product(): void
    {
        $this->givePurchaseHistory();

        $this->actingAs($this->customer)
            ->postJson("/api/v1/products/{$this->product->id}/reviews", [
                'rating' => 5,
                'comment' => 'Arrived quickly and works well.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.rating', 5);

        $this->assertDatabaseHas('reviews', [
            'user_id' => $this->customer->id,
            'product_id' => $this->product->id,
            'rating' => 5,
        ]);
    }

    /** The main defence against review spam. */
    #[Test]
    public function a_customer_who_never_bought_the_product_cannot_review_it(): void
    {
        $this->actingAs($this->customer)
            ->postJson("/api/v1/products/{$this->product->id}/reviews", ['rating' => 5])
            ->assertForbidden();

        $this->assertSame(0, Review::query()->count());
    }

    #[Test]
    public function a_seller_cannot_review_their_own_product(): void
    {
        $seller = User::query()->find($this->product->seller_id);

        Order::factory()->create(['user_id' => $seller->id])->items()->create([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => '10.00',
            'subtotal' => '10.00',
        ]);

        $this->actingAs($seller)
            ->postJson("/api/v1/products/{$this->product->id}/reviews", ['rating' => 5])
            ->assertForbidden();
    }

    #[Test]
    public function review_input_is_validated(): void
    {
        $this->givePurchaseHistory();

        $this->actingAs($this->customer)
            ->postJson("/api/v1/products/{$this->product->id}/reviews", ['rating' => 9])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rating');
    }

    /** unique(user_id, product_id) means a resubmission edits rather than duplicates. */
    #[Test]
    public function submitting_a_second_review_replaces_the_first(): void
    {
        $this->givePurchaseHistory();

        $this->actingAs($this->customer)
            ->postJson("/api/v1/products/{$this->product->id}/reviews", ['rating' => 2, 'comment' => 'Meh'])
            ->assertCreated();

        $this->actingAs($this->customer)
            ->postJson("/api/v1/products/{$this->product->id}/reviews", ['rating' => 4, 'comment' => 'Grew on me'])
            ->assertCreated()
            ->assertJsonPath('data.rating', 4);

        $this->assertSame(1, Review::query()->count());
    }

    #[Test]
    public function reviews_feed_the_average_rating_on_the_product_page(): void
    {
        Review::factory()->create(['product_id' => $this->product->id, 'rating' => 5]);
        Review::factory()->create(['product_id' => $this->product->id, 'rating' => 4]);

        $this->getJson("/api/v1/products/{$this->product->id}")
            ->assertOk()
            ->assertJsonPath('data.reviews_count', 2)
            ->assertJsonPath('data.average_rating', '4.5');
    }

    #[Test]
    public function a_customer_can_delete_their_own_review_but_not_anothers(): void
    {
        $mine = Review::factory()->create([
            'user_id' => $this->customer->id,
            'product_id' => $this->product->id,
        ]);
        $theirs = Review::factory()->create(['product_id' => $this->product->id]);

        $this->actingAs($this->customer)
            ->deleteJson("/api/v1/reviews/{$theirs->id}")
            ->assertForbidden();

        $this->actingAs($this->customer)
            ->deleteJson("/api/v1/reviews/{$mine->id}")
            ->assertOk();

        $this->assertDatabaseMissing('reviews', ['id' => $mine->id]);
        $this->assertDatabaseHas('reviews', ['id' => $theirs->id]);
    }

    #[Test]
    public function a_guest_cannot_post_a_review(): void
    {
        $this->postJson("/api/v1/products/{$this->product->id}/reviews", ['rating' => 5])
            ->assertUnauthorized();
    }

    /** Gives the customer a delivered order containing the product under test. */
    private function givePurchaseHistory(): void
    {
        OrderItem::factory()->create([
            'order_id' => Order::factory()->create(['user_id' => $this->customer->id])->id,
            'product_id' => $this->product->id,
        ]);
    }
}
