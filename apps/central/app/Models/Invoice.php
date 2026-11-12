<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Invoice extends Model
{
    protected $fillable = [
        'public_id', 'number', 'financial_year', 'customer_id', 'order_id', 'payment_id', 'status',
        'currency', 'subtotal_minor', 'discount_minor', 'tax_minor', 'total_minor',
        'place_of_supply', 'is_export', 'buyer_name', 'buyer_email', 'buyer_gstin', 'seller_snapshot', 'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'seller_snapshot' => 'array',
            'is_export' => 'boolean',
            'issued_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Invoice $i) => $i->public_id ??= (string) Str::ulid());
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function formattedTotal(): string
    {
        $symbol = $this->currency === 'INR' ? '₹' : '$';

        return $symbol.number_format($this->total_minor / 100, 2);
    }
}
