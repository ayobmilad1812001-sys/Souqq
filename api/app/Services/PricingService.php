<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Money;

/**
 * Pure pricing arithmetic. No database, no HTTP, no framework state -- which is
 * exactly why it is trivially unit testable and why every total in the system
 * is computed here rather than inline in a controller.
 */
final readonly class PricingService
{
    /**
     * @param  iterable<array{unit_price: Money, quantity: int}>  $lines
     */
    public function subtotal(iterable $lines): Money
    {
        $total = Money::zero();

        foreach ($lines as $line) {
            $total = $total->plus($this->lineSubtotal($line['unit_price'], $line['quantity']));
        }

        return $total;
    }

    public function lineSubtotal(Money $unitPrice, int $quantity): Money
    {
        return $unitPrice->times($quantity);
    }

    /**
     * Flat-rate shipping that becomes free once the basket reaches a threshold.
     *
     * Both figures come from config so they can be tuned per market without a
     * deploy, and an empty basket never attracts a shipping charge.
     */
    public function shippingCost(Money $subtotal): Money
    {
        if ($subtotal->isZero()) {
            return Money::zero();
        }

        $threshold = Money::of((string) config('marketplace.shipping.free_threshold'));

        if ($subtotal->greaterThanOrEqual($threshold)) {
            return Money::zero();
        }

        return Money::of((string) config('marketplace.shipping.flat_rate'));
    }

    public function total(Money $subtotal, Money $shipping): Money
    {
        return $subtotal->plus($shipping);
    }

    /**
     * Compute the complete money breakdown for a set of lines in one call, so
     * callers cannot accidentally pair a subtotal with a mismatched total.
     *
     * @param  iterable<array{unit_price: Money, quantity: int}>  $lines
     * @return array{subtotal: Money, shipping: Money, total: Money}
     */
    public function breakdown(iterable $lines): array
    {
        $subtotal = $this->subtotal($lines);
        $shipping = $this->shippingCost($subtotal);

        return [
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'total' => $this->total($subtotal, $shipping),
        ];
    }
}
