<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailCatchall extends Model
{
    protected $fillable = ['account_id', 'domain_id', 'destination'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
