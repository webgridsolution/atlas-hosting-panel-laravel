<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanPrice extends Model
{
    protected $fillable = ['plan_id', 'interval', 'currency', 'amount_minor', 'is_active'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'is_active' => 'boolean'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function formatted(): string
    {
        // Careful: PHP returns an int for exact division (49900/100 === 499),
        // so compare with fmod on a float — strict === against floor() is always false.
        $amount = (float) ($this->amount_minor / 100);
        $symbol = $this->currency === 'INR' ? '₹' : ($this->currency === 'USD' ? '$' : $this->currency.' ');

        return $symbol.number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2);
    }

    public function intervalLabel(): string
    {
        return match ($this->interval) {
            'monthly' => 'per month',
            'quarterly' => 'per quarter',
            'half_yearly' => 'per 6 months',
            'annual' => 'per year',
            'lifetime' => 'one-time',
            default => $this->interval,
        };
    }
}
