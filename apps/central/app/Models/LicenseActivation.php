<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseActivation extends Model
{
    protected $fillable = ['license_id', 'server_id', 'fingerprint_hash', 'ip_address', 'panel_version', 'status', 'activated_at', 'deactivated_at'];

    protected function casts(): array
    {
        return ['activated_at' => 'datetime', 'deactivated_at' => 'datetime'];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
