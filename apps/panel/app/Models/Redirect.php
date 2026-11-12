<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Redirect extends Model
{
    protected $fillable = [
        'account_id', 'domain_id', 'source_path', 'target_url',
        'status_code', 'hits', 'is_active',
    ];

    protected function casts(): array
    {
        return ['status_code' => 'integer', 'hits' => 'integer', 'is_active' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
