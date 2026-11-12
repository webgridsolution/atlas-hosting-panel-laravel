<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GitRepository extends Model
{
    protected $fillable = [
        'account_id', 'domain_id', 'path', 'remote', 'branch', 'status', 'last_deploy_at',
    ];

    protected function casts(): array
    {
        return ['last_deploy_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
