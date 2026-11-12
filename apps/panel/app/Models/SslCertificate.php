<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SslCertificate extends Model
{
    protected $fillable = [
        'account_id', 'domain_id', 'common_name', 'kind', 'san', 'cert_pem',
        'key_path', 'issuer', 'issued_at', 'expires_at', 'status',
    ];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function daysLeft(): ?int
    {
        return $this->expires_at ? (int) now()->startOfDay()->diffInDays($this->expires_at->startOfDay(), false) : null;
    }

    public function isExpiringSoon(): bool
    {
        $days = $this->daysLeft();

        return $days !== null && $days <= 14;
    }

    /** @return list<string> */
    public function subjectAltNames(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string) $this->san))));
    }
}
