<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'name', 'tagline', 'edition', 'is_active', 'is_featured', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_featured' => 'boolean'];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function priceFor(string $interval = 'monthly', string $currency = 'INR'): ?PlanPrice
    {
        return $this->prices
            ->first(fn (PlanPrice $p) => $p->interval === $interval && $p->currency === $currency)
            ?? $this->prices->first(fn (PlanPrice $p) => $p->interval === $interval);
    }

    public function entitlement(string $key): ?int
    {
        $row = $this->entitlements->firstWhere('feature_key', $key);

        return $row?->value;
    }
}
