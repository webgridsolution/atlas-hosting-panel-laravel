<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemoteMysqlHost extends Model
{
    protected $fillable = ['account_id', 'host', 'label'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
