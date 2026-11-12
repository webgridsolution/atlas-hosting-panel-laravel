<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Operation extends Model
{
    use HasFactory;
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'account_id', 'op', 'args_hash', 'status', 'detail', 'duration_ms',
    ];

    protected function casts(): array
    {
        return ['duration_ms' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** The agent journal is the permanent record; this mirror keeps 90 days. */
    public function prunable()
    {
        return static::where('created_at', '<=', now()->subDays(90));
    }

    public function scopeRecent($query)
    {
        return $query->orderByDesc('id')->limit(100);
    }
}
