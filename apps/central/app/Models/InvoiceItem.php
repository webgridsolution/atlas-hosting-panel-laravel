<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id', 'description', 'sac_code', 'quantity', 'unit_price_minor', 'line_total_minor',
        'taxable_minor', 'cgst_minor', 'sgst_minor', 'igst_minor', 'rate_basis_points',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
