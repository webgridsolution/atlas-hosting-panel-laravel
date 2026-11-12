<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mailbox extends Model
{
    use HasFactory;

    protected $fillable = ['account_id', 'address', 'local_part', 'domain', 'quota_mb'];

    protected function casts(): array
    {
        return ['quota_mb' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function scopeOfAccount($query, Account $account)
    {
        return $query->where('account_id', $account->id);
    }
}
