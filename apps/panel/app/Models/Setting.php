<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A tiny key/value store for decisions the operator makes in the UI
 * (hostname, nameservers, suspension policy). Values are cached as scalars so
 * a cached array never leaks back as a stale reference.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $cached = Cache::rememberForever('setting:'.$key, function () use ($key) {
            $row = static::query()->where('key', $key)->first();

            return ['v' => $row?->value];
        });

        return $cached['v'] ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('setting:'.$key);
    }
}
