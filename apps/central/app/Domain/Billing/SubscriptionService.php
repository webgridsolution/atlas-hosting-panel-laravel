<?php

namespace App\Domain\Billing;

use App\Domain\Licensing\IssueLicense;
use App\Enums\LicenseStatus;
use App\Models\License;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class SubscriptionService
{
    public function __construct(
        private readonly Pricing $pricing,
        private readonly IssueLicense $issueLicense,
    ) {}

    /** Creates the subscription and issues the licence that belongs to it. */
    public function startFromOrder(Order $order, Payment $payment): Subscription
    {
        $item = $order->items()->firstOrFail();
        $start = Carbon::now();
        $end = $this->pricing->periodEnd($start, $item->interval);

        $license = $this->issueLicense->handle($order->customer, $item->plan, $item->interval, $start);

        $license->update([
            'status' => LicenseStatus::Active,
            'expires_at' => $end,
        ]);

        return Subscription::create([
            'customer_id' => $order->customer_id,
            'plan_id' => $item->plan_id,
            'license_id' => $license->id,
            'interval' => $item->interval,
            'status' => 'active',
            'currency' => $order->currency,
            'amount_minor' => $order->total_minor,
            'current_period_start' => $start,
            'current_period_end' => $end,
        ]);
    }

    /** Extends the period and the licence. Called after a renewal payment is captured. */
    public function renew(Subscription $subscription, Carbon $paidAt): Subscription
    {
        return DB::transaction(function () use ($subscription, $paidAt) {
            $subscription = Subscription::lockForUpdate()->findOrFail($subscription->id);

            $base = $subscription->current_period_end->isFuture() ? $subscription->current_period_end : $paidAt;
            $end = $this->pricing->periodEnd($base, $subscription->interval);

            $subscription->update([
                'status' => 'active',
                'current_period_start' => $base,
                'current_period_end' => $end,
            ]);

            if ($subscription->license_id) {
                License::whereKey($subscription->license_id)->update([
                    'status' => LicenseStatus::Active,
                    'expires_at' => $end,
                ]);
            }

            return $subscription;
        });
    }

    public function cancel(Subscription $subscription, bool $immediate = false): Subscription
    {
        $subscription->update([
            'cancel_at_period_end' => ! $immediate,
            'status' => $immediate ? 'cancelled' : $subscription->status,
            'cancelled_at' => $immediate ? now() : null,
        ]);

        return $subscription;
    }
}
