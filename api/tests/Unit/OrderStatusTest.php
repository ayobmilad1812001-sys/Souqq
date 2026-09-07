<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\OrderStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The order state machine. Pure logic, so it is tested without a database.
 */
final class OrderStatusTest extends TestCase
{
    #[Test]
    public function it_allows_the_forward_fulfilment_path(): void
    {
        $this->assertTrue(OrderStatus::Pending->canTransitionTo(OrderStatus::Confirmed));
        $this->assertTrue(OrderStatus::Confirmed->canTransitionTo(OrderStatus::Processing));
        $this->assertTrue(OrderStatus::Processing->canTransitionTo(OrderStatus::Shipped));
        $this->assertTrue(OrderStatus::Shipped->canTransitionTo(OrderStatus::Delivered));
    }

    #[Test]
    public function it_rejects_skipping_and_reversing_steps(): void
    {
        $this->assertFalse(OrderStatus::Pending->canTransitionTo(OrderStatus::Shipped));
        $this->assertFalse(OrderStatus::Delivered->canTransitionTo(OrderStatus::Shipped));
        $this->assertFalse(OrderStatus::Shipped->canTransitionTo(OrderStatus::Processing));
    }

    #[Test]
    public function terminal_states_accept_nothing(): void
    {
        $this->assertTrue(OrderStatus::Delivered->isTerminal());
        $this->assertTrue(OrderStatus::Cancelled->isTerminal());
        $this->assertSame([], OrderStatus::Cancelled->allowedTransitions());
        $this->assertFalse(OrderStatus::Cancelled->canTransitionTo(OrderStatus::Pending));
    }

    #[Test]
    public function an_order_may_be_cancelled_only_before_dispatch(): void
    {
        $this->assertTrue(OrderStatus::Pending->canTransitionTo(OrderStatus::Cancelled));
        $this->assertTrue(OrderStatus::Confirmed->canTransitionTo(OrderStatus::Cancelled));
        $this->assertTrue(OrderStatus::Processing->canTransitionTo(OrderStatus::Cancelled));

        // Once it has physically shipped, cancellation is a returns problem,
        // not a status change.
        $this->assertFalse(OrderStatus::Shipped->canTransitionTo(OrderStatus::Cancelled));
    }

    #[Test]
    public function only_pre_dispatch_statuses_return_stock(): void
    {
        $this->assertTrue(OrderStatus::Pending->releasesStockOnCancel());
        $this->assertTrue(OrderStatus::Processing->releasesStockOnCancel());
        $this->assertFalse(OrderStatus::Shipped->releasesStockOnCancel());
        $this->assertFalse(OrderStatus::Delivered->releasesStockOnCancel());
    }

    #[Test]
    public function customers_may_only_self_cancel_early_orders(): void
    {
        $this->assertTrue(OrderStatus::Pending->isCustomerCancellable());
        $this->assertTrue(OrderStatus::Confirmed->isCustomerCancellable());
        // Once a seller is picking and packing, cancellation goes through staff.
        $this->assertFalse(OrderStatus::Processing->isCustomerCancellable());
    }
}
