<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per account per day.
 *
 * `day` is a SQL date, so it is stored as Y-m-d. The panel writes and looks
 * rows up by that string; a plain `date` cast would serialise Y-m-d H:i:s,
 * which MySQL truncates on write but not on lookup — the second visit to the
 * bandwidth screen would then violate the unique index.
 */
class BandwidthDay extends Model
{
    protected $fillable = ['account_id', 'day', 'http_mb', 'mail_mb', 'ftp_mb'];

    protected function casts(): array
    {
        return ['http_mb' => 'integer', 'mail_mb' => 'integer', 'ftp_mb' => 'integer'];
    }

    protected function day(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? Carbon::parse($value)->startOfDay() : null,
            set: fn ($value) => $value ? Carbon::parse($value)->toDateString() : null,
        );
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function totalMb(): int
    {
        return $this->http_mb + $this->mail_mb + $this->ftp_mb;
    }
}
