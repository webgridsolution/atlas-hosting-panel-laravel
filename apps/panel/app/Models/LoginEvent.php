<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginEvent extends Model
{
    protected $fillable = [
        'user_id', 'account_id', 'area', 'email', 'ip',
        'user_agent', 'successful', 'reason',
    ];

    protected function casts(): array
    {
        return ['successful' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function deviceLabel(): string
    {
        $ua = strtolower((string) $this->user_agent);

        return match (true) {
            str_contains($ua, 'iphone') => 'iPhone',
            str_contains($ua, 'ipad') => 'iPad',
            str_contains($ua, 'android') => 'Android',
            str_contains($ua, 'macintosh') => 'Mac',
            str_contains($ua, 'windows') => 'Windows',
            str_contains($ua, 'curl') => 'curl',
            str_contains($ua, 'firefox') => 'Firefox',
            default => 'unknown device',
        };
    }
}
