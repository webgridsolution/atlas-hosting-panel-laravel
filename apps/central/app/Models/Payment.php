<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Payment extends Model
{
    protected $fillable = [
        'public_id', 'order_id', 'customer_id', 'gateway', 'gateway_payment_id', 'gateway_order_id',
        'status', 'currency', 'amount_minor', 'method', 'signature_verified_at', 'captured_at', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_verified_at' => 'datetime',
            'captured_at' => 'datetime',
            'amount_minor' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Payment $p) => $p->public_id ??= (string) Str::ulid());
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function isCaptured(): bool
    {
        return $this->status === 'captured';
    }
}
