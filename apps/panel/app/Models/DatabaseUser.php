<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A MySQL user separate from the account's own user, so an application can be
 * given exactly the grants it needs. MySQL keys a grant on user@host, which is
 * why the unique key spans both columns.
 */
class DatabaseUser extends Model
{
    public const PRIVILEGE_ALL = 'ALL';

    public const PRIVILEGE_READ = 'READ';

    public const PRIVILEGE_WRITE = 'WRITE';

    protected $fillable = ['account_id', 'username', 'host', 'privileges', 'status', 'last_used_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function databases(): BelongsToMany
    {
        return $this->belongsToMany(HostingDatabase::class, 'database_user_hosting_database')
            ->withTimestamps();
    }

    /** What the customer picked, in words. */
    public function privilegeLabel(): string
    {
        return match ($this->privileges) {
            self::PRIVILEGE_READ => 'Read only',
            self::PRIVILEGE_WRITE => 'Read and write',
            default => 'All privileges',
        };
    }
}
