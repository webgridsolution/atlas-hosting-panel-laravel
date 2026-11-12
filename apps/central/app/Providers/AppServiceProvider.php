<?php

namespace App\Providers;

use App\Domain\Billing\DemoPaymentGateway;
use App\Domain\Billing\GstCalculator;
use App\Domain\Billing\PaymentGateway;
use App\Domain\Billing\RazorpayGateway;
use App\Listeners\ProvisionCustomerAccount;
use Illuminate\Auth\Events\Registered;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, function () {
            if (config('services.payments.driver') === 'demo') {
                return new DemoPaymentGateway;
            }

            return new RazorpayGateway(
                (string) config('services.razorpay.key_id'),
                (string) config('services.razorpay.key_secret'),
            );
        });

        $this->app->singleton(GstCalculator::class, fn () => new GstCalculator(
            (string) config('billing.seller_state', 'JH'),
        ));
    }

    public function boot(): void
    {
        Paginator::defaultView('pagination::atlas');
        Paginator::defaultSimpleView('pagination::atlas');

        Event::listen(Registered::class, ProvisionCustomerAccount::class);

        RateLimiter::for('license', function (Request $request) {
            $key = (string) ($request->input('key') ?? 'anon');

            return [
                Limit::perHour(10)->by('ip:'.$request->ip()),
                Limit::perHour(10)->by('key:'.$key),
            ];
        });

        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
    }
}
