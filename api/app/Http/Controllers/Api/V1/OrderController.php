<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\IndexOrderRequest;
use App\Http\Requests\Order\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Support\ApiResponse;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    /**
     * Role-scoped listing. The scoping happens in the query, not in a filter
     * applied afterwards, so a seller can never receive another sellers rows
     * even in a paginated edge case.
     */
    public function index(IndexOrderRequest $request): JsonResponse
    {
        $user = $request->user();

        $paginator = $this->scopedQuery($user)
            ->status($request->string('status')->value() ?: null)
            ->with(['items.product'])
            ->withCount('items')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success(OrderResource::collection($paginator), 'Orders retrieved.');
    }

    /**
     * Checkout. All of the interesting work -- the transaction, the row locks,
     * the stock arithmetic -- lives in OrderService; the controller only turns
     * the result into HTTP.
     */
    public function store(Request $request): JsonResponse
    {
        $order = $this->orders->place($request->user());

        return ApiResponse::created(
            OrderResource::make($order->load('items.product')),
            'Order placed successfully.'
        );
    }

    public function show(Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        return ApiResponse::success(
            OrderResource::make($order->load(['items.product', 'user'])),
            'Order retrieved.'
        );
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        $target = $request->status();

        $this->authorize('updateStatus', [$order, $target]);

        return ApiResponse::success(
            OrderResource::make($this->orders->transitionTo($order, $target)->load('items.product')),
            'Order status updated.'
        );
    }

    /** Customer self-service cancellation, subject to the time window. */
    public function cancel(Order $order): JsonResponse
    {
        $this->authorize('cancel', $order);

        return ApiResponse::success(
            OrderResource::make($this->orders->cancelAsCustomer($order)->load('items.product')),
            'Order cancelled.'
        );
    }

    /** @return Builder<Order> */
    private function scopedQuery(User $user): Builder
    {
        if ($user->isAdmin()) {
            return Order::query();
        }

        if ($user->isSeller()) {
            return Order::query()->containingProductsOf($user->id);
        }

        return Order::query()->where('user_id', $user->id);
    }
}
