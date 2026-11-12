<x-app-layout title="Tickets" :crumbs="[['label' => 'Tickets']]">
    <div class="page-head">
        <div>
            <h1>Support queue</h1>
            <p>Newest activity first. Priority changes are audited.</p>
        </div>
    </div>

    <div class="row wrap mb-2" style="gap:6px">
        <a href="{{ route('admin.tickets.index') }}" class="chip" style="font-weight:{{ ! $status ? '600' : '400' }}">All</a>
        @foreach (['open' => 'Open', 'pending' => 'Awaiting customer', 'resolved' => 'Resolved', 'closed' => 'Closed'] as $value => $label)
            <a href="{{ route('admin.tickets.index', ['status' => $value]) }}" class="chip" style="font-weight:{{ $status === $value ? '600' : '400' }}">{{ $label }}</a>
        @endforeach
    </div>

    @if ($tickets->isEmpty())
        <x-empty icon="ticket" title="Queue is clear" description="No tickets match this filter." />
    @else
        <div class="card">
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Ticket</th><th>Customer</th><th>Subject</th><th>Priority</th><th>Status</th><th>Assignee</th><th>Updated</th></tr></thead>
                    <tbody>
                        @foreach ($tickets as $ticket)
                            <tr>
                                <td class="mono">{{ $ticket->number }}</td>
                                <td>
                                    <span class="cell-strong">{{ $ticket->customer->user->name }}</span>
                                    <span class="cell-sub">{{ $ticket->customer->user->email }}</span>
                                </td>
                                <td><a href="{{ route('admin.tickets.show', $ticket) }}">{{ \Illuminate\Support\Str::limit($ticket->subject, 52) }}</a></td>
                                <td><span class="badge {{ $ticket->priorityBadge() }}">{{ ucfirst($ticket->priority) }}</span></td>
                                <td><span class="badge {{ $ticket->statusBadge() }}">{{ ucfirst($ticket->status) }}</span></td>
                                <td class="small">{{ $ticket->assignee?->name ?? '—' }}</td>
                                <td class="num small">{{ ($ticket->last_reply_at ?? $ticket->created_at)->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $tickets->links() }}
        </div>
    @endif
</x-app-layout>
