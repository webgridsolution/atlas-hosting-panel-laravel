<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['license_id', 'type', 'actor_type', 'actor_id', 'ip_address', 'meta', 'created_at'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public static function record(License $license, string $type, array $context = []): self
    {
        return static::create([
            'license_id' => $license->id,
            'type' => $type,
            'actor_type' => $context['actor_type'] ?? 'system',
            'actor_id' => $context['actor_id'] ?? null,
            'ip_address' => $context['ip'] ?? null,
            'meta' => $context['meta'] ?? null,
            'created_at' => now(),
        ]);
    }
}
