<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Release extends Model
{
    protected $fillable = ['public_id', 'component', 'version', 'channel', 'notes', 'is_security_release', 'is_published', 'published_at'];

    protected function casts(): array
    {
        return ['is_security_release' => 'boolean', 'is_published' => 'boolean', 'published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (Release $r) => $r->public_id ??= (string) Str::ulid());
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(ReleaseArtifact::class);
    }
}
