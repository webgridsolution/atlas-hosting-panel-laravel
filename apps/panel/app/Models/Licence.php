<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Licence extends Model
{
    use HasFactory;

    protected $table = 'licences';

    public const ACTIVE = 'active';

    public const GRACE = 'grace';

    public const READ_ONLY = 'read_only';

    public const UNLICENSED = 'unlicensed';

    protected $fillable = [
        'token', 'licence_key', 'fingerprint', 'kid', 'state', 'features',
        'expires_at', 'last_checked_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            // A bearer credential for the vendor API: encrypted at rest, and
            // never rendered anywhere.
            'licence_key' => 'encrypted',
            'expires_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function feature(string $name): bool
    {
        return (bool) ($this->features[$name] ?? false);
    }

    public static function current(): self
    {
        return static::query()->latest('id')->first() ?? new static(['state' => self::UNLICENSED]);
    }
}
