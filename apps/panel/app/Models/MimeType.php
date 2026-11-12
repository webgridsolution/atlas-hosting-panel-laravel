<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MimeType extends Model
{
    protected $fillable = ['account_id', 'extension', 'mime_type'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
