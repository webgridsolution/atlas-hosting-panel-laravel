<?php

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;

class SupportTicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('tickets.view') || $user->can('portal.access');
    }

    public function view(User $user, SupportTicket $ticket): bool
    {
        return $user->can('tickets.view') || $ticket->customer?->user_id === $user->id;
    }

    public function reply(User $user, SupportTicket $ticket): bool
    {
        return $user->can('tickets.reply');
    }

    public function update(User $user, SupportTicket $ticket): bool
    {
        return $user->can('tickets.assign');
    }
}
