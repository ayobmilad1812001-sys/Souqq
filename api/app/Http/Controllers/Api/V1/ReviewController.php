<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Review\StoreReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Product;
use App\Models\Review;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class ReviewController extends Controller
{
    public function index(Product $product): JsonResponse
    {
        $reviews = $product->reviews()
            ->with('user:id,name')
            ->latest()
            ->paginate(15);

        return ApiResponse::success(
            ReviewResource::collection($reviews),
            'Reviews retrieved.'
        );
    }

    /**
     * Create or replace this customers review of a product.
     *
     * ReviewPolicy restricts this to verified purchasers who do not sell the
     * product themselves. updateOrCreate honours the unique(user, product)
     * index, so a second submission edits rather than duplicates.
     */
    public function store(StoreReviewRequest $request, Product $product): JsonResponse
    {
        $this->authorize('create', [Review::class, $product]);

        $review = Review::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'product_id' => $product->id],
            $request->validated(),
        );

        return ApiResponse::created(
            ReviewResource::make($review->load('user:id,name')),
            'Review submitted.'
        );
    }

    public function destroy(Review $review): JsonResponse
    {
        $this->authorize('delete', $review);

        $review->delete();

        return ApiResponse::deleted('Review deleted.');
    }
}
