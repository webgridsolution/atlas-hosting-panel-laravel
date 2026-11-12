<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailSetting extends Model
{
    protected $fillable = [
        'account_id', 'domain_id', 'spam_threshold', 'spam_action',
        'dkim_enabled', 'spf_enabled', 'greylisting', 'mailbox_quota_mb',
    ];

    protected function casts(): array
    {
        return [
            'spam_threshold' => 'decimal:1',
            'dkim_enabled' => 'boolean',
            'spf_enabled' => 'boolean',
            'greylisting' => 'boolean',
            'mailbox_quota_mb' => 'integer',
        ];
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
