<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailFilter extends Model
{
    protected $fillable = [
        'account_id', 'domain_id', 'mailbox', 'field', 'operator', 'value',
        'action', 'action_arg', 'priority', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'priority' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /** Human-readable rule, e.g. `Subject contains "invoice" -> move (Invoices)`. */
    public function describe(): string
    {
        return sprintf(
            '%s %s "%s" -> %s%s',
            ucfirst($this->field),
            $this->operator,
            $this->value,
            str_replace('_', ' ', $this->action),
            $this->action_arg ? ' ('.$this->action_arg.')' : '',
        );
    }
}
