<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One-shot nonces that make activation and heartbeat requests replay-proof.
 * Rows are purged after 15 minutes; a duplicate insert is the replay signal.
 */
class ActivationNonce extends Model
{
    protected $fillable = ['nonce', 'fingerprint_hash', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
