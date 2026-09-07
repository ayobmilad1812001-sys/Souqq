<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\IndexProductRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ProductService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Thin by design: validation lives in the Form Requests, authorization in
 * ProductPolicy, business rules in ProductService, and shaping in the Resource.
 */
final class ProductController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    public function index(IndexProductRequest $request): JsonResponse
    {
        $paginator = $this->products->paginate($request->filters(), $request->user());

        return ApiResponse::success(
            ProductResource::collection($paginator),
            'Products retrieved.'
        );
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        return ApiResponse::created(
            ProductResource::make($this->products->create($request->user(), $request->validated())),
            'Product created.'
        );
    }

    public function show(int $product): JsonResponse
    {
        // Served from the cached single-product read.
        $model = $this->products->find($product);

        abort_if($model === null, 404, 'Resource not found.');
        $this->authorize('view', $model);

        return ApiResponse::success(ProductResource::make($model), 'Product retrieved.');
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        // Enforces the seller-isolation rule: a seller updating a product they
        // do not own gets a 403 before any write is attempted.
        $this->authorize('update', $product);

        return ApiResponse::success(
            ProductResource::make($this->products->update($product, $request->validated())),
            'Product updated.'
        );
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        $this->products->delete($product);

        return ApiResponse::deleted('Product deleted.');
    }
}
