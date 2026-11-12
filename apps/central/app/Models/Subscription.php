<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Subscription extends Model
{
    protected $fillable = [
        'public_id', 'customer_id', 'plan_id', 'license_id', 'interval', 'status', 'currency', 'amount_minor',
        'current_period_start', 'current_period_end', 'cancel_at_period_end', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Subscription $s) => $s->public_id ??= (string) Str::ulid());
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'trialing'], true);
    }
}
