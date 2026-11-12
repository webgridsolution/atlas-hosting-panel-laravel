<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Coupon extends Model
{
    protected $fillable = ['code', 'kind', 'value', 'currency', 'max_redemptions', 'redeemed_count', 'starts_at', 'ends_at', 'is_active'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** Discount in minor units for a given subtotal, or 0 if this coupon does not apply. */
    public function discountFor(int $subtotalMinor, string $currency): int
    {
        if (! $this->isRedeemable()) {
            return 0;
        }

        if ($this->kind === 'percent') {
            // value is basis points: 1000 = 10%
            return (int) round($subtotalMinor * $this->value / 10000);
        }

        if ($this->currency && strtoupper($this->currency) !== strtoupper($currency)) {
            return 0;
        }

        return min($subtotalMinor, (int) $this->value);
    }

    public function isRedeemable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->ends_at && $this->ends_at->isPast()) {
            return false;
        }

        return $this->max_redemptions === null || $this->redeemed_count < $this->max_redemptions;
    }
}
