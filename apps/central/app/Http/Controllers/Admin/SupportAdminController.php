<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupportAdminController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', SupportTicket::class);

        return view('admin.support.index', [
            'tickets' => SupportTicket::with(['customer.user', 'assignee'])
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->when($request->query('priority'), fn ($q, $p) => $q->where('priority', $p))
                ->latest('last_reply_at')->latest()
                ->paginate(20)->withQueryString(),
            'status' => $request->query('status'),
            'priority' => $request->query('priority'),
        ]);
    }

    public function show(SupportTicket $ticket): View
    {
        $this->authorize('view', $ticket);

        return view('admin.support.show', [
            'ticket' => $ticket->load(['customer.user', 'replies.user', 'assignee']),
            'staff' => User::permission('tickets.reply')->orderBy('name')->get(),
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $this->authorize('reply', $ticket);

        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:10000']]);

        $ticket->replies()->create([
            'user_id' => $request->user()->id,
            'is_staff' => true,
            'body' => $data['body'],
        ]);

        $ticket->update(['status' => 'pending', 'last_reply_at' => now()]);
        AuditLog::record('ticket.replied', $ticket);

        return back()->with('status', 'Reply sent.');
    }

    public function update(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $this->authorize('update', $ticket);

        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'pending', 'resolved', 'closed'])],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $ticket->update([
            'status' => $data['status'],
            'priority' => $data['priority'],
            'assigned_to' => $data['assigned_to'],
            'closed_at' => in_array($data['status'], ['resolved', 'closed'], true) ? now() : null,
        ]);

        AuditLog::record('ticket.updated', $ticket, $data);

        return back()->with('status', 'Ticket updated.');
    }
}
