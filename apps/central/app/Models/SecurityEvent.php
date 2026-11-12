<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityEvent extends Model
{
    protected $fillable = ['type', 'severity', 'ip_address', 'actor', 'meta', 'resolved_at'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'resolved_at' => 'datetime'];
    }

    public static function raise(string $type, string $severity, array $meta = []): self
    {
        return static::create([
            'type' => $type,
            'severity' => $severity,
            'ip_address' => request()->ip(),
            'actor' => request()->userAgent(),
            'meta' => $meta ?: null,
        ]);
    }
}
