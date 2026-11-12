<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory;
    use HasRoles;
    use Notifiable;

    protected $fillable = ['name', 'email', 'password', 'is_active', 'last_login_at'];

    // The TOTP secret never leaves the model. A hidden attribute is what stops
    // it appearing in a JSON response or a dump without anybody noticing.
    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
            // A second factor is a password: encrypted at rest, and the recovery
            // codes are a JSON array that gets the same treatment.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** Has this login finished enrolling a second factor? */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /** The account this login belongs to (customers only). */
    public function account(): ?Account
    {
        return $this->accounts()->first();
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /** Accounts this user resells. */
    public function managedAccounts(): HasMany
    {
        return $this->hasMany(Account::class, 'reseller_id');
    }

    public function homeRoute(): string
    {
        return match (true) {
            $this->hasRole('admin') => 'whm.dashboard',
            $this->hasRole('reseller') => 'reseller.dashboard',
            default => 'panel.dashboard',
        };
    }
}
