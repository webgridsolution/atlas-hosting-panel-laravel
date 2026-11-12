<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailForwarder extends Model
{
    protected $fillable = ['account_id', 'domain_id', 'source', 'destination', 'keep_copy', 'is_active'];

    protected function casts(): array
    {
        return ['keep_copy' => 'boolean', 'is_active' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /** @return list<string> */
    public function destinations(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[\s,;]+/', (string) $this->destination) ?: [],
        )));
    }
}
