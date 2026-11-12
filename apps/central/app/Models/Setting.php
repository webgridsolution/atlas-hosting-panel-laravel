<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'group', 'type', 'label'];

    public static function get(string $key, mixed $default = null): mixed
    {
        // Cache the scalar payload, never the Eloquent model: a serialized model
        // becomes an incomplete object as soon as the class map changes.
        $row = Cache::rememberForever("setting.{$key}", function () use ($key) {
            $row = static::where('key', $key)->first(['type', 'value']);

            return $row ? ['type' => $row->type, 'value' => $row->value] : null;
        });

        if (! $row) {
            return $default;
        }

        return match ($row['type']) {
            'boolean' => (bool) $row['value'],
            'integer' => (int) $row['value'],
            'json' => json_decode($row['value'] ?? '[]', true),
            'secret' => $row['value'] ? Crypt::decryptString($row['value']) : null,
            default => $row['value'],
        };
    }

    public static function put(string $key, mixed $value, string $group = 'general', string $type = 'string', string $label = ''): self
    {
        $stored = match ($type) {
            'json' => json_encode($value),
            'secret' => $value ? Crypt::encryptString((string) $value) : null,
            'boolean' => $value ? '1' : '0',
            default => $value === null ? null : (string) $value,
        };

        $row = static::updateOrCreate(['key' => $key], [
            'value' => $stored, 'group' => $group, 'type' => $type, 'label' => $label ?: $key,
        ]);

        Cache::forget("setting.{$key}");

        return $row;
    }
}
