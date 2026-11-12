<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Server extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id', 'customer_id', 'license_id', 'hostname', 'ip_address',
        'os', 'panel_version', 'fingerprint_hash', 'status', 'last_heartbeat_at',
    ];

    protected function casts(): array
    {
        return ['last_heartbeat_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Server $server) {
            $server->public_id ??= (string) Str::ulid();
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            'online' => 'badge-ok',
            'offline' => 'badge-danger',
            default => 'badge-neutral',
        };
    }

    public function isStale(): bool
    {
        return $this->last_heartbeat_at === null
            || $this->last_heartbeat_at->lt(now()->subHours(26));
    }
}
