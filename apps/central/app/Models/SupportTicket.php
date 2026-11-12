<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SupportTicket extends Model
{
    protected $fillable = [
        'public_id', 'number', 'customer_id', 'license_id', 'assigned_to',
        'subject', 'category', 'priority', 'status', 'last_reply_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return ['last_reply_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (SupportTicket $ticket) {
            $ticket->public_id ??= (string) Str::ulid();
            $ticket->number ??= 'TKT-'.random_int(1000, 9999);
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class, 'ticket_id')->oldest();
    }

    public function priorityBadge(): string
    {
        return match ($this->priority) {
            'urgent' => 'badge-danger',
            'high' => 'badge-warn',
            'low' => 'badge-neutral',
            default => 'badge-info',
        };
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            'open' => 'badge-info',
            'pending' => 'badge-warn',
            'resolved' => 'badge-ok',
            default => 'badge-neutral',
        };
    }
}
