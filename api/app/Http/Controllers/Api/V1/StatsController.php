<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sales dashboards. Sellers see only their own numbers; admins see the platform.
 *
 * Every figure is produced by a single aggregate query rather than by loading
 * rows into PHP -- the difference between a constant-cost endpoint and one that
 * degrades as the marketplace grows.
 */
final class StatsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success(
            $user->isAdmin() ? $this->platformStats() : $this->sellerStats($user),
            'Statistics retrieved.'
        );
    }

    /** @return array<string, mixed> */
    private function sellerStats(User $user): array
    {
        $revenue = OrderItem::query()
            ->whereHas('product', fn ($query) => $query->where('seller_id', $user->id))
            ->whereHas('order', fn ($query) => $query->where('status', '!=', OrderStatus::Cancelled))
            ->sum('subtotal');

        return [
            'scope' => 'seller',
            'products_total' => Product::query()->where('seller_id', $user->id)->count(),
            'products_active' => Product::query()->where('seller_id', $user->id)->where('is_active', true)->count(),
            'out_of_stock' => Product::query()->where('seller_id', $user->id)->where('stock_quantity', 0)->count(),
            'units_sold' => (int) OrderItem::query()
                ->whereHas('product', fn ($query) => $query->where('seller_id', $user->id))
                ->whereHas('order', fn ($query) => $query->where('status', '!=', OrderStatus::Cancelled))
                ->sum('quantity'),
            // Aggregated as a string and re-parsed, never as a float.
            'gross_revenue' => Money::of((string) ($revenue ?: '0.00'))->toDecimalString(),
        ];
    }

    /** @return array<string, mixed> */
    private function platformStats(): array
    {
        $revenue = Order::query()
            ->where('status', '!=', OrderStatus::Cancelled)
            ->sum('total');

        return [
            'scope' => 'platform',
            'users_total' => User::query()->count(),
            'sellers_total' => User::query()->where('role', 'seller')->count(),
            'products_total' => Product::query()->count(),
            'orders_total' => Order::query()->count(),
            'orders_by_status' => Order::query()
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status'),
            'gross_revenue' => Money::of((string) ($revenue ?: '0.00'))->toDecimalString(),
        ];
    }
}
