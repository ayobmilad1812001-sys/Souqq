<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\CategoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class CategoryController extends Controller
{
    public function __construct(private readonly CategoryService $categories) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            CategoryResource::collection($this->categories->all()),
            'Categories retrieved.'
        );
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', Category::class);

        return ApiResponse::created(
            CategoryResource::make($this->categories->create($request->validated())),
            'Category created.'
        );
    }

    public function show(Category $category): JsonResponse
    {
        return ApiResponse::success(
            CategoryResource::make($category->loadCount('products')),
            'Category retrieved.'
        );
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $this->authorize('update', $category);

        return ApiResponse::success(
            CategoryResource::make($this->categories->update($category, $request->validated())),
            'Category updated.'
        );
    }

    public function destroy(Category $category): JsonResponse
    {
        $this->authorize('delete', $category);

        // Deleting a category that still has products would orphan listings, so
        // the service refuses and we report the conflict rather than cascading.
        if (! $this->categories->delete($category)) {
            return ApiResponse::error(
                'This category still contains products and cannot be deleted.',
                Response::HTTP_CONFLICT
            );
        }

        return ApiResponse::deleted('Category deleted.');
    }
}
