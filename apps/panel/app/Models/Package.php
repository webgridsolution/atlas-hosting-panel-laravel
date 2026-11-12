<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'description', 'disk_mb', 'inodes', 'bandwidth_mb',
        'max_domains', 'max_databases', 'max_mailboxes', 'php_version',
        'price_paise', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'disk_mb' => 'integer',
            'inodes' => 'integer',
            'bandwidth_mb' => 'integer',
            'max_domains' => 'integer',
            'max_databases' => 'integer',
            'max_mailboxes' => 'integer',
            'price_paise' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /** Human-readable disk limit, e.g. "10 GB". */
    public function diskLabel(): string
    {
        return $this->disk_mb >= 1024
            ? round($this->disk_mb / 1024, $this->disk_mb % 1024 === 0 ? 0 : 1).' GB'
            : $this->disk_mb.' MB';
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
