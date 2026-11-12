<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Autoresponder extends Model
{
    protected $fillable = [
        'account_id', 'domain_id', 'mailbox', 'subject', 'body',
        'starts_at', 'ends_at', 'is_active',
    ];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /** Within its window *and* switched on. */
    public function isRunning(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }

        return ! ($this->ends_at && $now->gt($this->ends_at));
    }
}
