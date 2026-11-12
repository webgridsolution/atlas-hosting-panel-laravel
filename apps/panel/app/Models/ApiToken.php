<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The plaintext token is shown once and never stored; only its SHA-256 is kept,
 * so a database dump cannot be replayed against the API.
 */
class ApiToken extends Model
{
    protected $fillable = [
        'user_id', 'account_id', 'name', 'token_hash', 'prefix',
        'abilities', 'last_used_at', 'expires_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['abilities' => 'array', 'last_used_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return list<string> */
    public function abilityList(): array
    {
        return array_values((array) ($this->abilities ?? []));
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function allows(string $ability): bool
    {
        return ! $this->isExpired() && in_array($ability, $this->abilityList(), true);
    }
}
