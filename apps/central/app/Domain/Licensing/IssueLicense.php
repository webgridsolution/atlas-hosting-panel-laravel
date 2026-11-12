<?php

namespace App\Domain\Licensing;

use App\Enums\LicenseStatus;
use App\Models\Customer;
use App\Models\License;
use App\Models\Plan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues a licence for a customer/plan.
 *
 * In Phase 2 this is called from the admin console. In Phase 3 the Razorpay
 * payment listener is the only production caller; keeping the entry point
 * identical means the switch is a one-line change, not a rewrite.
 */
final class IssueLicense
{
    public function __construct(private readonly LicenseKeyGenerator $keys) {}

    public function handle(
        Customer $customer,
        Plan $plan,
        string $interval = 'monthly',
        ?Carbon $startsAt = null,
    ): License {
        if (! $plan->is_active) {
            throw ValidationException::withMessages(['plan_id' => 'This plan is not available for new licences.']);
        }

        $startsAt ??= Carbon::now();

        return DB::transaction(function () use ($customer, $plan, $interval, $startsAt) {
            $license = new License([
                'customer_id' => $customer->id,
                'plan_id' => $plan->id,
                'key' => $this->uniqueKey(),
                'status' => LicenseStatus::PendingActivation,
                'activation_limit' => 1,
                'expires_at' => $this->expiryFor($interval, $startsAt),
                'grace_days' => 14,
            ]);

            $license->save();

            return $license;
        });
    }

    private function uniqueKey(): string
    {
        // Collision probability is negligible (2^80 space), but the unique index
        // is the real guarantee — retry rather than gamble in production.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $key = $this->keys->generate();

            if (! License::where('key', $key)->exists()) {
                return $key;
            }
        }

        throw new \RuntimeException('Unable to allocate a unique licence key.');
    }

    private function expiryFor(string $interval, Carbon $start): ?Carbon
    {
        return match ($interval) {
            'monthly' => $start->copy()->addMonthNoOverflow(),
            'quarterly' => $start->copy()->addMonthsNoOverflow(3),
            'half_yearly' => $start->copy()->addMonthsNoOverflow(6),
            'annual' => $start->copy()->addYearNoOverflow(),
            'lifetime' => null,
            default => throw ValidationException::withMessages(['interval' => 'Unknown billing interval.']),
        };
    }
}
