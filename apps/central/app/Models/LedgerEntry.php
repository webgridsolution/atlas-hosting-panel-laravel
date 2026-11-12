<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only double-entry ledger. UPDATE and DELETE are blocked at the database
 * level (see LedgerService::guard()); a row is never mutated after posting.
 */
class LedgerEntry extends Model
{
    protected $table = 'financial_ledger';

    protected $fillable = [
        'entry_group', 'account', 'debit_minor', 'credit_minor', 'currency',
        'reference_type', 'reference_id', 'description', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'debit_minor' => 'integer',
            'credit_minor' => 'integer',
        ];
    }
}
