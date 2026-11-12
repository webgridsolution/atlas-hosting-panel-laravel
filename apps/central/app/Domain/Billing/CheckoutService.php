<?php

namespace App\Domain\Billing;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates an order and a provider-side order. The amount is computed here,
 * server-side, from the plan catalogue — never accepted from the browser.
 */
final class CheckoutService
{
    public function __construct(
        private readonly Pricing $pricing,
        private readonly GstCalculator $gst,
        private readonly PaymentGateway $gateway,
    ) {}

    public function start(
        Customer $customer,
        Plan $plan,
        string $interval,
        string $currency = 'INR',
        ?string $couponCode = null,
        array $meta = [],
    ): Order {
        if (! $plan->is_active) {
            throw ValidationException::withMessages(['plan' => 'This plan is not available.']);
        }

        $coupon = $this->resolveCoupon($couponCode);
        $priced = $this->pricing->price($plan, $interval, $currency, $coupon);

        $state = $customer->billing_address['state'] ?? null;
        $tax = $this->gst->calculate($priced['taxable'], $customer->country, $state);

        $total = $priced['taxable'] + $tax['total_tax'];

        return DB::transaction(function () use ($customer, $plan, $interval, $currency, $coupon, $priced, $tax, $total, $meta) {
            $order = Order::create([
                'customer_id' => $customer->id,
                'coupon_id' => $coupon?->id,
                'status' => 'pending',
                'currency' => $currency,
                'subtotal_minor' => $priced['subtotal'],
                'discount_minor' => $priced['coupon_discount'],
                'tax_minor' => $tax['total_tax'],
                'total_minor' => $total,
                'gateway' => $this->gateway->name(),
                'meta' => array_merge($meta, ['tax' => $tax, 'period_discount_minor' => $priced['period_discount']]),
            ]);

            $order->items()->create([
                'plan_id' => $plan->id,
                'description' => "{$plan->name} licence — {$interval}",
                'interval' => $interval,
                'quantity' => 1,
                'unit_price_minor' => $priced['subtotal'],
                'line_total_minor' => $priced['subtotal'],
            ]);

            $providerOrder = $this->gateway->createOrder(
                receipt: $order->number,
                amountMinor: $order->total_minor,
                currency: $currency,
                notes: ['order' => $order->public_id, 'customer' => (string) $customer->id, 'plan' => $plan->slug],
            );

            $order->update(['gateway_order_id' => $providerOrder['id']]);

            if ($coupon) {
                $coupon->increment('redeemed_count');
            }

            return $order->fresh(['items', 'coupon']);
        });
    }

    private function resolveCoupon(?string $code): ?Coupon
    {
        if (! $code) {
            return null;
        }

        $coupon = Coupon::where('code', strtoupper(trim($code)))->first();

        if (! $coupon || ! $coupon->isRedeemable()) {
            // Uniform failure: never reveal whether the code exists.
            throw ValidationException::withMessages(['coupon' => 'That coupon code is not valid.']);
        }

        return $coupon;
    }
}
