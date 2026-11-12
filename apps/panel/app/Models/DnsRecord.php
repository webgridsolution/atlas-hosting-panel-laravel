<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsRecord extends Model
{
    use HasFactory;

    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];

    protected $fillable = ['dns_zone_id', 'name', 'type', 'content', 'ttl', 'priority'];

    protected function casts(): array
    {
        return [
            'ttl' => 'integer',
            'priority' => 'integer',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class, 'dns_zone_id');
    }

    public function fqdn(): string
    {
        $zone = $this->zone?->domain ?? '';

        return $this->name === '@' ? $zone : $this->name.'.'.$zone;
    }
}
