<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Listeners\LogOrderStatusChange;
use App\Listeners\NotifySellersOfNewOrder;
use App\Listeners\SendOrderConfirmation;
use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Observers\CategoryObserver;
use App\Observers\ProductObserver;
use App\Observers\ReviewObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureServeCommand();
        $this->configureModels();
        $this->configureObservers();
        $this->configureEvents();
        $this->configureRateLimiting();
    }

    /**
     * Work around a Laravel bug that breaks `artisan serve` on Windows.
     *
     * ServeCommand blanks every environment variable that is not in its
     * $passthroughVariables whitelist before spawning the PHP dev server. The
     * whitelist carries 'SYSTEMROOT', but Windows names the variable
     * 'SystemRoot' and in_array() is case-sensitive, so the real one is wiped.
     * Without it the child process cannot initialise Winsock, every bind()
     * fails, and the server reports "Failed to listen ... (reason: ?)" on
     * every port it tries.
     */
    private function configureServeCommand(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return;
        }

        foreach (['SystemRoot', 'windir', 'TEMP', 'TMP'] as $variable) {
            if (! in_array($variable, ServeCommand::$passthroughVariables, true)) {
                ServeCommand::$passthroughVariables[] = $variable;
            }
        }
    }

    private function configureModels(): void
    {
        // Fail loudly in development on lazy loading (the N+1 trap), on
        // assigning attributes that do not exist, and on accessing attributes
        // that were never selected. In production these would turn latent bugs
        // into 500s, so strictness is limited to non-production environments.
        Model::shouldBeStrict(! $this->app->isProduction());
    }

    private function configureObservers(): void
    {
        Product::observe(ProductObserver::class);
        Category::observe(CategoryObserver::class);
        Review::observe(ReviewObserver::class);
    }

    private function configureEvents(): void
    {
        Event::listen(OrderCreated::class, SendOrderConfirmation::class);
        Event::listen(OrderCreated::class, NotifySellersOfNewOrder::class);
        Event::listen(OrderStatusChanged::class, LogOrderStatusChange::class);
    }

    /**
     * Public endpoints are throttled per authenticated user, falling back to IP
     * for guests, so one noisy client cannot exhaust the catalogue for everyone.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        // Credential endpoints get a much tighter budget: this is the primary
        // defence against password spraying.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(6)
            ->by($request->input('email').'|'.$request->ip()));

        // Checkout is expensive (row locks, transaction) and no human needs to
        // place ten orders a minute.
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
