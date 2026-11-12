<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Backup extends Model
{
    use HasFactory;

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'account_id', 'kind', 'target', 'archive', 'bytes', 'status', 'detail', 'restored_at',
    ];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'restored_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function sizeLabel(): string
    {
        $b = (int) $this->bytes;

        return match (true) {
            $b >= 1 << 30 => round($b / (1 << 30), 1).' GB',
            $b >= 1 << 20 => round($b / (1 << 20), 1).' MB',
            $b >= 1 << 10 => round($b / 1024).' KB',
            default => $b.' B',
        };
    }
}
