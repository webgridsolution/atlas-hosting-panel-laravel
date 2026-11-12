<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DnsZone extends Model
{
    use HasFactory;

    protected $fillable = ['account_id', 'domain', 'status'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(DnsRecord::class)->orderBy('type')->orderBy('name');
    }

    /**
     * The records an agent write needs: name, type, content, ttl, priority.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toZonePayload(): array
    {
        return $this->records->map(fn (DnsRecord $r) => [
            'name' => $r->name,
            'type' => $r->type,
            'content' => $r->content,
            'ttl' => $r->ttl,
            'priority' => $r->priority ?? 0,
        ])->all();
    }
}
