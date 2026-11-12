<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['actor_id', 'actor_type', 'action', 'subject_type', 'subject_id', 'changes', 'ip_address', 'user_agent', 'created_at'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function actorLabel(): string
    {
        return $this->actor?->name ?? match ($this->actor_type) {
            'system' => 'system',
            'panel' => 'panel agent',
            default => 'unknown',
        };
    }

    public static function record(string $action, ?object $subject = null, array $changes = [], array $context = []): self
    {
        return static::create([
            'actor_id' => $context['actor_id'] ?? auth()->id(),
            'actor_type' => $context['actor_type'] ?? 'user',
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'changes' => $changes ?: null,
            'ip_address' => $context['ip'] ?? request()->ip(),
            'user_agent' => $context['user_agent'] ?? substr((string) request()->userAgent(), 0, 255),
            'created_at' => now(),
        ]);
    }
}
