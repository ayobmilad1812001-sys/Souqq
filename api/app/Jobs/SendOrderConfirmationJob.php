<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends the order confirmation email off the request thread.
 *
 * Talking to an SMTP relay inside the checkout request would add hundreds of
 * milliseconds to the slowest, most conversion-sensitive endpoint in the API,
 * and a mail outage would surface to shoppers as a failed checkout for an order
 * that actually succeeded.
 */
final class SendOrderConfirmationJob implements ShouldQueue
{
    use Queueable;

    /** Retry with growing gaps -- transient SMTP failures usually clear fast. */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $orderId) {}

    public function handle(): void
    {
        $order = Order::query()
            ->with(['items.product', 'user'])
            ->find($this->orderId);

        // The order may have been removed between dispatch and execution; that
        // is not a failure worth retrying.
        if ($order === null) {
            return;
        }

        Mail::to($order->user->email)->send(new OrderConfirmationMail($order));
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Order confirmation email permanently failed.', [
            'order_id' => $this->orderId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
