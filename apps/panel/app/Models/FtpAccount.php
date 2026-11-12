<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FtpAccount extends Model
{
    protected $fillable = [
        'account_id', 'domain_id', 'username', 'home_dir', 'quota_mb',
        'read_only', 'status', 'last_login_at', 'last_login_ip',
    ];

    protected function casts(): array
    {
        return ['read_only' => 'boolean', 'quota_mb' => 'integer', 'last_login_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function quotaLabel(): string
    {
        return $this->quota_mb === 0 ? 'Unlimited' : $this->quota_mb.' MB';
    }
}
