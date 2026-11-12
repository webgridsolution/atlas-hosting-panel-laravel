<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HostingDatabase extends Model
{
    use HasFactory;

    protected $fillable = ['account_id', 'name', 'db_user', 'size_mb'];

    protected function casts(): array
    {
        return ['size_mb' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
