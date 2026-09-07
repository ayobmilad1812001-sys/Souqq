<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Money is the foundation every total rests on, so it is tested in isolation
 * from the framework -- a plain PHPUnit TestCase, no database, no container.
 */
final class MoneyTest extends TestCase
{
    #[Test]
    public function it_parses_decimal_strings_exactly(): void
    {
        $this->assertSame('1234.50', Money::of('1234.50')->toDecimalString());
        $this->assertSame('0.01', Money::of('0.01')->toDecimalString());
        $this->assertSame('0.00', Money::of('0')->toDecimalString());
        $this->assertSame('99.90', Money::of('99.9')->toDecimalString());
    }

    #[Test]
    public function it_rounds_extra_decimal_places_half_up(): void
    {
        $this->assertSame('0.01', Money::of('0.005')->toDecimalString());
        $this->assertSame('0.00', Money::of('0.004')->toDecimalString());
        $this->assertSame('10.13', Money::of('10.125')->toDecimalString());
    }

    #[Test]
    public function it_handles_negative_amounts(): void
    {
        $this->assertSame('-5.25', Money::of('-5.25')->toDecimalString());
        $this->assertTrue(Money::of('-0.01')->isNegative());
    }

    #[Test]
    public function it_rejects_malformed_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of('12.34.56');
    }

    /**
     * The whole reason this class exists: repeatedly adding 0.10 as a float
     * drifts away from the exact answer, while integer minor units do not.
     */
    #[Test]
    public function summing_many_small_amounts_stays_exact(): void
    {
        $total = Money::zero();

        for ($i = 0; $i < 1000; $i++) {
            $total = $total->plus(Money::of('0.10'));
        }

        $this->assertSame('100.00', $total->toDecimalString());
    }

    #[Test]
    public function multiplication_by_quantity_is_exact(): void
    {
        $this->assertSame('59.97', Money::of('19.99')->times(3)->toDecimalString());
        $this->assertSame('0.00', Money::of('19.99')->times(0)->toDecimalString());
    }

    #[Test]
    public function subtraction_and_comparison_behave(): void
    {
        $ten = Money::of('10.00');
        $three = Money::of('3.00');

        $this->assertSame('7.00', $ten->minus($three)->toDecimalString());
        $this->assertTrue($ten->greaterThanOrEqual($three));
        $this->assertTrue($three->lessThan($ten));
        $this->assertTrue($ten->equals(Money::of('10.00')));
    }

    #[Test]
    public function sum_folds_a_collection(): void
    {
        $amounts = [Money::of('1.11'), Money::of('2.22'), Money::of('3.33')];

        $this->assertSame('6.66', Money::sum($amounts)->toDecimalString());
        $this->assertSame('0.00', Money::sum([])->toDecimalString());
    }

    #[Test]
    #[DataProvider('provideAmounts')]
    public function it_round_trips_through_its_string_form(string $input, string $expected): void
    {
        $this->assertSame($expected, Money::of(Money::of($input)->toDecimalString())->toDecimalString());
    }

    /** @return array<string, array{string, string}> */
    public static function provideAmounts(): array
    {
        return [
            'whole number' => ['42', '42.00'],
            'two decimals' => ['42.42', '42.42'],
            'one decimal' => ['42.4', '42.40'],
            'large value' => ['99999999.99', '99999999.99'],
            'sub-unit' => ['0.07', '0.07'],
        ];
    }
}
