<?php

namespace App\Models;

use App\Enums\LicenseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class License extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id', 'customer_id', 'plan_id', 'key', 'status', 'activation_limit',
        'fingerprint_hash', 'activated_at', 'expires_at', 'last_seen_at', 'grace_days',
    ];

    protected $hidden = ['key'];

    protected function casts(): array
    {
        return [
            'status' => LicenseStatus::class,
            'activated_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'activation_limit' => 'integer',
            'grace_days' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (License $license) {
            $license->public_id ??= (string) Str::ulid();
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function activations(): HasMany
    {
        return $this->hasMany(LicenseActivation::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(LicenseEvent::class)->latest('created_at');
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            LicenseStatus::Active->value,
            LicenseStatus::Grace->value,
            LicenseStatus::PendingActivation->value,
        ]);
    }

    /** Derived state: an active licence past expires_at is "grace" until the grace window closes. */
    public function effectiveStatus(): LicenseStatus
    {
        if (in_array($this->status, [LicenseStatus::Suspended, LicenseStatus::Revoked], true)) {
            return $this->status;
        }

        if ($this->expires_at === null) {
            return $this->status;
        }

        $now = Carbon::now();

        if ($now->lessThanOrEqualTo($this->expires_at)) {
            return $this->status === LicenseStatus::PendingActivation
                ? LicenseStatus::PendingActivation
                : LicenseStatus::Active;
        }

        $graceEnds = $this->expires_at->copy()->addDays($this->grace_days);

        return $now->lessThanOrEqualTo($graceEnds) ? LicenseStatus::Grace : LicenseStatus::Expired;
    }

    public function daysUntilExpiry(): ?int
    {
        return $this->expires_at ? (int) now()->startOfDay()->diffInDays($this->expires_at->copy()->startOfDay(), false) : null;
    }

    public function maskedKey(): string
    {
        // Keys are stored in plaintext for activation matching; display is always masked.
        if (! $this->key) {
            return '••••-••••';
        }

        return Str::substr($this->key, 0, 5).'-••••-••••-'.Str::substr($this->key, -4);
    }
}
