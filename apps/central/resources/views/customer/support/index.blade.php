<x-app-layout title="Support" :crumbs="[['label' => 'Support']]">
    <div class="page-head">
        <div>
            <h1>Support tickets</h1>
            <p>Billing, licensing, installation and technical issues — answered by the team that builds the panel.</p>
        </div>
    </div>

    <div class="grid cols-3" style="gap:20px;align-items:start">
        <div class="card">
            <div class="card-head"><h2>Open a ticket</h2></div>
            <form method="POST" action="{{ route('support.store') }}" class="card-body">
                @csrf
                <x-input label="Subject" name="subject" required placeholder="Panel install fails at step 4" />
                <div class="grid cols-2" style="gap:12px">
                    <x-select label="Category" name="category" required>
                        <option value="general">General</option>
                        <option value="installation">Installation</option>
                        <option value="licensing">Licensing</option>
                        <option value="billing">Billing</option>
                        <option value="technical">Technical</option>
                        <option value="abuse">Abuse</option>
                    </x-select>
                    <x-select label="Priority" name="priority" required>
                        <option value="low">Low</option>
                        <option value="normal" selected>Normal</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                    </x-select>
                </div>
                <x-select label="Related licence (optional)" name="license_id">
                    <option value="">Not licence-specific</option>
                    @foreach ($licenses as $license)
                        <option value="{{ $license->id }}">{{ $license->public_id }} · {{ $license->plan->name }}</option>
                    @endforeach
                </x-select>
                <x-textarea label="Describe the problem" name="body" rows="6" required
                            placeholder="What did you run, what did you expect, what happened? Paste the relevant installer log lines." />
                <div class="form-actions">
                    <button class="btn btn-primary">Open ticket</button>
                </div>
            </form>
        </div>

        <div class="span-2" style="grid-column:span 2">
            @if ($tickets->isEmpty())
                <x-empty icon="ticket" title="No tickets yet" description="When you open a ticket it appears here with the full reply thread." />
            @else
                <div class="card">
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>Ticket</th><th>Subject</th><th>Status</th><th>Priority</th><th>Replies</th><th>Updated</th></tr></thead>
                            <tbody>
                                @foreach ($tickets as $ticket)
                                    <tr>
                                        <td class="mono">{{ $ticket->number }}</td>
                                        <td>
                                            <a href="{{ route('support.show', $ticket) }}" class="cell-strong" style="text-decoration:none">{{ $ticket->subject }}</a>
                                            <span class="cell-sub">{{ ucfirst($ticket->category) }}</span>
                                        </td>
                                        <td><span class="badge {{ $ticket->statusBadge() }}">{{ ucfirst($ticket->status) }}</span></td>
                                        <td class="small">{{ ucfirst($ticket->priority) }}</td>
                                        <td class="num">{{ $ticket->replies_count }}</td>
                                        <td class="num small">{{ $ticket->last_reply_at?->diffForHumans() ?? $ticket->created_at->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{ $tickets->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
