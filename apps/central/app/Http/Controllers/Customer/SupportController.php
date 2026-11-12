<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupportController extends Controller
{
    public function index(Request $request): View
    {
        return view('customer.support.index', [
            'tickets' => SupportTicket::where('customer_id', $request->user()->customer->id)
                ->withCount('replies')->latest()->paginate(15),
            'licenses' => $request->user()->customer->licenses()->with('plan')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'min:6', 'max:200'],
            'category' => ['required', 'in:general,installation,licensing,billing,technical,abuse'],
            'priority' => ['required', 'in:low,normal,high,urgent'],
            'license_id' => ['nullable', 'integer'],
            'body' => ['required', 'string', 'min:20', 'max:10000'],
        ]);

        if (! empty($data['license_id'])) {
            abort_unless(
                $request->user()->customer->licenses()->whereKey($data['license_id'])->exists(),
                403,
            );
        }

        $ticket = SupportTicket::create([
            'customer_id' => $request->user()->customer->id,
            'license_id' => $data['license_id'] ?? null,
            'subject' => $data['subject'],
            'category' => $data['category'],
            'priority' => $data['priority'],
            'status' => 'open',
            'last_reply_at' => now(),
        ]);

        $ticket->replies()->create([
            'user_id' => $request->user()->id,
            'is_staff' => false,
            'body' => $data['body'],
        ]);

        return redirect()->route('support.show', $ticket)->with('status', "Ticket {$ticket->number} opened.");
    }

    public function show(Request $request, SupportTicket $ticket): View
    {
        abort_unless($ticket->customer_id === $request->user()->customer->id, 403);

        return view('customer.support.show', ['ticket' => $ticket->load(['replies.user'])]);
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        abort_unless($ticket->customer_id === $request->user()->customer->id, 403);

        if (in_array($ticket->status, ['closed'], true)) {
            return back()->withErrors(['body' => 'This ticket is closed. Open a new one if the issue persists.']);
        }

        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:10000']]);

        $ticket->replies()->create([
            'user_id' => $request->user()->id,
            'is_staff' => false,
            'body' => $data['body'],
        ]);

        $ticket->update(['status' => 'open', 'last_reply_at' => now()]);

        return back()->with('status', 'Reply added.');
    }
}
