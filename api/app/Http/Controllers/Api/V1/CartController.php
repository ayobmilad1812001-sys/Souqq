<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\StoreCartItemRequest;
use App\Http\Requests\Cart\UpdateCartItemRequest;
use App\Http\Resources\CartResource;
use App\Models\CartItem;
use App\Models\Product;
use App\Services\CartService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every endpoint returns the *whole* cart rather than just the mutated line, so
 * a client never has to re-fetch to refresh its totals after an edit.
 */
final class CartController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(
            CartResource::make($this->cart->forUser($request->user())),
            'Cart retrieved.'
        );
    }

    public function storeItem(StoreCartItemRequest $request): JsonResponse
    {
        $product = Product::query()->findOrFail($request->integer('product_id'));

        $cart = $this->cart->addItem(
            $request->user(),
            $product,
            $request->integer('quantity'),
        );

        return ApiResponse::created(CartResource::make($cart), 'Item added to cart.');
    }

    public function updateItem(UpdateCartItemRequest $request, CartItem $item): JsonResponse
    {
        // CartItemPolicy: you may only touch lines in your own cart.
        $this->authorize('update', $item);

        return ApiResponse::success(
            CartResource::make($this->cart->updateItem($item, $request->integer('quantity'))),
            'Cart item updated.'
        );
    }

    public function destroyItem(CartItem $item): JsonResponse
    {
        $this->authorize('delete', $item);

        return ApiResponse::success(
            CartResource::make($this->cart->removeItem($item)),
            'Cart item removed.'
        );
    }
}
