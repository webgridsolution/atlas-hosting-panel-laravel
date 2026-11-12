<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailingList extends Model
{
    protected $fillable = [
        'account_id', 'domain_id', 'name', 'title', 'members', 'subscribers', 'status',
    ];

    protected function casts(): array
    {
        return ['subscribers' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function address(): string
    {
        return $this->name.'@'.$this->domain?->domain;
    }

    /** @return list<string> */
    public function memberAddresses(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[\s,;]+/', (string) $this->members) ?: [],
        )));
    }
}
