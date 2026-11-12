<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Feature extends Model
{
    protected $fillable = ['key', 'label', 'category', 'type', 'sort_order'];

    /** Human value for an entitlement row. */
    public static function formatValue(string $type, int $value): string
    {
        if ($type === 'boolean') {
            return $value ? 'Included' : '—';
        }

        return $value === -1 ? 'Unlimited' : number_format($value);
    }
}
