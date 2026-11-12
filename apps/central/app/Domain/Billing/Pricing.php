<?php

namespace App\Domain\Billing;

use App\Models\Coupon;
use App\Models\Plan;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Server-side pricing. The browser never supplies an amount.
 * Every number here is derived from the database and this class's rules.
 */
final class Pricing
{
    /** Interval length in months. */
    public const INTERVAL_MONTHS = [
        'monthly' => 1,
        'quarterly' => 3,
        'half_yearly' => 6,
        'annual' => 12,
    ];

    /** Multi-period discount applied to the monthly-equivalent rate, basis points. */
    public const INTERVAL_DISCOUNT_BP = [
        'monthly' => 0,
        'quarterly' => 500,    // 5%
        'half_yearly' => 1000, // 10%
        'annual' => 1500,      // 15%
    ];

    /** @return array{subtotal:int, period_discount:int, subtotal_after_period:int, coupon_discount:int, taxable:int} */
    public function price(Plan $plan, string $interval, string $currency = 'INR', ?Coupon $coupon = null): array
    {
        $months = self::INTERVAL_MONTHS[$interval] ?? null;

        if ($months === null) {
            throw ValidationException::withMessages(['interval' => 'Unsupported billing interval.']);
        }

        $explicit = $plan->prices
            ->first(fn ($p) => $p->interval === $interval && $p->currency === $currency && $p->is_active);

        if ($explicit) {
            $subtotal = (int) $explicit->amount_minor;
            $periodDiscount = 0;
        } else {
            $monthly = $plan->prices
                ->first(fn ($p) => $p->interval === 'monthly' && $p->currency === $currency && $p->is_active);

            if (! $monthly) {
                throw ValidationException::withMessages(['plan' => 'This plan has no price in the selected currency.']);
            }

            $listTotal = (int) $monthly->amount_minor * $months;
            $periodDiscount = (int) round($listTotal * (self::INTERVAL_DISCOUNT_BP[$interval] ?? 0) / 10000);
            $subtotal = $listTotal - $periodDiscount;
        }

        $couponDiscount = $coupon?->discountFor($subtotal, $currency) ?? 0;
        $taxable = max(0, $subtotal - $couponDiscount);

        return [
            'subtotal' => $subtotal,
            'period_discount' => $periodDiscount,
            'subtotal_after_period' => $subtotal + $periodDiscount, // list price before period discount
            'coupon_discount' => $couponDiscount,
            'taxable' => $taxable,
        ];
    }

    public function periodEnd(Carbon $start, string $interval): Carbon
    {
        return match ($interval) {
            'monthly' => $start->copy()->addMonthNoOverflow(),
            'quarterly' => $start->copy()->addMonthsNoOverflow(3),
            'half_yearly' => $start->copy()->addMonthsNoOverflow(6),
            'annual' => $start->copy()->addYearNoOverflow(),
            default => $start->copy()->addMonthNoOverflow(),
        };
    }
}
