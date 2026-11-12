<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Refund extends Model
{
    protected $fillable = [
        'public_id', 'payment_id', 'invoice_id', 'gateway_refund_id', 'status',
        'amount_minor', 'reason', 'requested_by', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime', 'amount_minor' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (Refund $r) => $r->public_id ??= (string) Str::ulid());
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
