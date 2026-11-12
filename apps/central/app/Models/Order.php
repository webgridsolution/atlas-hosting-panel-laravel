<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Order extends Model
{
    protected $fillable = [
        'public_id', 'number', 'customer_id', 'coupon_id', 'status', 'currency',
        'subtotal_minor', 'discount_minor', 'tax_minor', 'total_minor',
        'gateway', 'gateway_order_id', 'meta', 'paid_at',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array', 'paid_at' => 'datetime', 'total_minor' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->public_id ??= (string) Str::ulid();
            $order->number ??= 'ORD-'.strtoupper(Str::random(8));
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function isPayable(): bool
    {
        return $this->status === 'pending';
    }
}
