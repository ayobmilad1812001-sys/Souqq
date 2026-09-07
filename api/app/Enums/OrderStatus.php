<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Order lifecycle.
 *
 * The allowed transitions are encoded here rather than scattered across
 * controllers/services, so "can this order move from X to Y?" has exactly one
 * answer in the codebase and one place to unit test.
 *
 *   pending -> confirmed -> processing -> shipped -> delivered
 *      |           |            |
 *      +-----------+------------+--------> cancelled
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /** @return array<int, self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Processing, self::Cancelled],
            self::Processing => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Delivered],
            // Terminal states.
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }

    /** Terminal statuses can never change again. */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Stock is only returned to inventory when an order is cancelled *before*
     * it has physically left the warehouse.
     */
    public function releasesStockOnCancel(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed, self::Processing], strict: true);
    }

    /** Statuses a customer is allowed to cancel from (subject to the time window). */
    public function isCustomerCancellable(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed], strict: true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
