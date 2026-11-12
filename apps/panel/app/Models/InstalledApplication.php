<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstalledApplication extends Model
{
    protected $fillable = [
        'account_id', 'domain_id', 'slug', 'path', 'version', 'url',
        'admin_user', 'db_name', 'status', 'installed_at', 'checked_at',
    ];

    protected function casts(): array
    {
        return ['installed_at' => 'datetime', 'checked_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /** The catalogue's human name for this slug. */
    public function label(): string
    {
        $catalogue = (array) config('atlas.apps', []);

        return $catalogue[$this->slug]['label'] ?? ucfirst((string) $this->slug);
    }

    public function hasUpdate(): bool
    {
        $catalogue = (array) config('atlas.apps', []);
        $latest = $catalogue[$this->slug]['version'] ?? $this->version;

        return $latest !== null && version_compare((string) $latest, (string) $this->version, '>');
    }
}
