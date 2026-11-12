<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Domain extends Model
{
    use HasFactory;

    public const SSL_NONE = 'none';

    public const SSL_PENDING = 'pending';

    public const SSL_ACTIVE = 'active';

    public const SSL_FAILED = 'failed';

    public const TYPE_PRIMARY = 'primary';

    public const TYPE_ADDON = 'addon';

    public const TYPE_SUBDOMAIN = 'subdomain';

    public const TYPE_PARKED = 'parked';

    protected $fillable = [
        'account_id', 'domain', 'document_root', 'is_primary',
        'ssl_status', 'ssl_expires_at', 'ssl_issuer',
        'type', 'parent_id', 'label', 'php_version', 'mail_enabled', 'status', 'provisioned_at',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'mail_enabled' => 'boolean',
            'ssl_expires_at' => 'datetime',
            'provisioned_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** The domain this subdomain or alias hangs off. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(SslCertificate::class);
    }

    public function redirects(): HasMany
    {
        return $this->hasMany(Redirect::class);
    }

    /** The PHP version actually serving this site. */
    public function effectivePhpVersion(): string
    {
        return (string) ($this->php_version ?: $this->account?->php_version ?: '8.3');
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_ADDON => 'Addon domain',
            self::TYPE_SUBDOMAIN => 'Subdomain',
            self::TYPE_PARKED => 'Alias',
            default => 'Primary',
        };
    }

    public function sslLabel(): string
    {
        return match ($this->ssl_status) {
            self::SSL_ACTIVE => 'Secured',
            self::SSL_PENDING => 'Pending',
            self::SSL_FAILED => 'Failed',
            default => 'No certificate',
        };
    }

    public function sslExpiringSoon(): bool
    {
        return $this->ssl_status === self::SSL_ACTIVE
            && $this->ssl_expires_at !== null
            && $this->ssl_expires_at->isBefore(now()->addDays(14));
    }
}
