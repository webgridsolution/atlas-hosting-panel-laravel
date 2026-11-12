<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class LicenseKey extends Model
{
    protected $fillable = ['kid', 'public_key', 'private_key_enc', 'valid_from', 'valid_until', 'is_active'];

    protected $hidden = ['private_key_enc'];

    protected function casts(): array
    {
        return ['valid_from' => 'datetime', 'valid_until' => 'datetime', 'is_active' => 'boolean'];
    }

    public function privateKey(): string
    {
        return Crypt::decryptString($this->private_key_enc);
    }

    public function isUsable(): bool
    {
        return $this->is_active
            && $this->valid_from->isPast()
            && ($this->valid_until === null || $this->valid_until->isFuture());
    }
}
