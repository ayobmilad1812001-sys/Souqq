<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable monetary value object.
 *
 * WHY THIS EXISTS
 * ---------------
 * PHP floats cannot represent most decimal fractions exactly, so the classic
 * 0.1 + 0.2 !== 0.3 problem silently corrupts order totals. Every amount in
 * this application is therefore stored and manipulated as an integer number of
 * minor units (cents) and only rendered as a DECIMAL(10,2) compatible string at
 * the boundary.
 *
 * All arithmetic here is integer arithmetic: exact, associative, and safe to
 * sum over thousands of line items. Nothing in the money path touches a float
 * except fromFloat(), which exists purely to catch a stray float leaking in
 * from a third party and rounds it immediately.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    public const SCALE = 2;

    private const MINOR_UNITS = 100;

    private function __construct(public int $minorUnits) {}

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromMinorUnits(int $minorUnits): self
    {
        return new self($minorUnits);
    }

    /**
     * Parse a decimal string such as "1234.50" (the shape MySQL returns for a
     * DECIMAL(10,2) column). Accepts an optional sign and any number of
     * decimals, rounding half-up to two.
     */
    public static function of(string|int|float|self $amount): self
    {
        if ($amount instanceof self) {
            return $amount;
        }

        if (is_int($amount)) {
            return new self($amount * self::MINOR_UNITS);
        }

        if (is_float($amount)) {
            return self::fromFloat($amount);
        }

        $normalised = trim($amount);

        if (! preg_match('/^(?<sign>[+-]?)(?<whole>\d+)(?:\.(?<fraction>\d+))?$/', $normalised, $matches)) {
            throw new InvalidArgumentException("Malformed monetary amount: [{$amount}].");
        }

        $fraction = $matches['fraction'] ?? '';
        $minor = (int) $matches['whole'] * self::MINOR_UNITS;

        if ($fraction !== '') {
            $twoDigits = (int) str_pad(substr($fraction, 0, self::SCALE), self::SCALE, '0');
            $remainder = substr($fraction, self::SCALE, 1);
            $minor += $twoDigits + ($remainder !== '' && (int) $remainder >= 5 ? 1 : 0);
        }

        return new self($matches['sign'] === '-' ? -$minor : $minor);
    }

    /** Last-resort conversion for values arriving from outside as floats. */
    public static function fromFloat(float $amount): self
    {
        return new self((int) round($amount * self::MINOR_UNITS));
    }

    public function plus(self $other): self
    {
        return new self($this->minorUnits + $other->minorUnits);
    }

    public function minus(self $other): self
    {
        return new self($this->minorUnits - $other->minorUnits);
    }

    /**
     * Multiply by a whole quantity. Quantities are always integers in this
     * domain, which keeps the product exact.
     */
    public function times(int $multiplier): self
    {
        return new self($this->minorUnits * $multiplier);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->minorUnits >= $other->minorUnits;
    }

    public function lessThan(self $other): bool
    {
        return $this->minorUnits < $other->minorUnits;
    }

    /**
     * Fold a list of amounts. Summing integers makes the result independent of
     * ordering, unlike a float sum.
     *
     * @param  iterable<self>  $amounts
     */
    public static function sum(iterable $amounts): self
    {
        $total = 0;

        foreach ($amounts as $amount) {
            $total += $amount->minorUnits;
        }

        return new self($total);
    }

    /** Canonical "0.00" representation written to DECIMAL(10,2) columns. */
    public function toDecimalString(): string
    {
        $sign = $this->minorUnits < 0 ? '-' : '';
        $absolute = abs($this->minorUnits);

        return sprintf(
            '%s%d.%02d',
            $sign,
            intdiv($absolute, self::MINOR_UNITS),
            $absolute % self::MINOR_UNITS
        );
    }

    public function jsonSerialize(): string
    {
        return $this->toDecimalString();
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }
}
