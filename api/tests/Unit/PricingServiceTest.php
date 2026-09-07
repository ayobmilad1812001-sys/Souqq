<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PricingService;
use App\Support\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pricing needs the container only for config(), so it uses the framework
 * TestCase but never touches the database.
 */
final class PricingServiceTest extends TestCase
{
    private PricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'marketplace.shipping.flat_rate' => '15.00',
            'marketplace.shipping.free_threshold' => '500.00',
        ]);

        $this->pricing = new PricingService;
    }

    #[Test]
    public function it_multiplies_a_line_exactly(): void
    {
        $this->assertSame(
            '59.97',
            $this->pricing->lineSubtotal(Money::of('19.99'), 3)->toDecimalString()
        );
    }

    #[Test]
    public function it_sums_lines_without_drift(): void
    {
        $lines = [
            ['unit_price' => Money::of('0.10'), 'quantity' => 3],
            ['unit_price' => Money::of('0.20'), 'quantity' => 1],
            ['unit_price' => Money::of('19.99'), 'quantity' => 2],
        ];

        // 0.30 + 0.20 + 39.98 -- a float sum of these lands on 40.479999...
        $this->assertSame('40.48', $this->pricing->subtotal($lines)->toDecimalString());
    }

    #[Test]
    public function shipping_is_flat_below_the_free_threshold(): void
    {
        $this->assertSame('15.00', $this->pricing->shippingCost(Money::of('499.99'))->toDecimalString());
    }

    #[Test]
    public function shipping_is_free_at_and_above_the_threshold(): void
    {
        $this->assertSame('0.00', $this->pricing->shippingCost(Money::of('500.00'))->toDecimalString());
        $this->assertSame('0.00', $this->pricing->shippingCost(Money::of('500.01'))->toDecimalString());
    }

    #[Test]
    public function an_empty_basket_is_never_charged_shipping(): void
    {
        $this->assertSame('0.00', $this->pricing->shippingCost(Money::zero())->toDecimalString());
    }

    #[Test]
    public function breakdown_returns_a_consistent_triple(): void
    {
        $breakdown = $this->pricing->breakdown([
            ['unit_price' => Money::of('100.00'), 'quantity' => 2],
        ]);

        $this->assertSame('200.00', $breakdown['subtotal']->toDecimalString());
        $this->assertSame('15.00', $breakdown['shipping']->toDecimalString());
        $this->assertSame('215.00', $breakdown['total']->toDecimalString());
    }

    #[Test]
    public function a_large_free_shipping_basket_totals_to_its_subtotal(): void
    {
        $breakdown = $this->pricing->breakdown([
            ['unit_price' => Money::of('249.99'), 'quantity' => 4],
        ]);

        $this->assertSame('999.96', $breakdown['subtotal']->toDecimalString());
        $this->assertSame('0.00', $breakdown['shipping']->toDecimalString());
        $this->assertSame('999.96', $breakdown['total']->toDecimalString());
    }
}
