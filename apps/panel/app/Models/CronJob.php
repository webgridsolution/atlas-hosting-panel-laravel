<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CronJob extends Model
{
    use HasFactory;

    protected $fillable = ['account_id', 'schedule', 'command', 'description', 'last_run_at'];

    protected function casts(): array
    {
        return ['last_run_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** The literal crontab line the agent installs, shown in the UI. */
    public function line(): string
    {
        return $this->schedule.' '.$this->command;
    }
}
