<x-app-layout :title="$ticket->number" :crumbs="[['label' => 'Tickets', 'url' => route('admin.tickets.index')], ['label' => $ticket->number]]">
    <div class="page-head">
        <div>
            <div class="row" style="gap:10px;align-items:baseline">
                <h1>{{ $ticket->subject }}</h1>
                <span class="badge {{ $ticket->statusBadge() }}">{{ ucfirst($ticket->status) }}</span>
                <span class="badge {{ $ticket->priorityBadge() }}">{{ ucfirst($ticket->priority) }}</span>
            </div>
            <p class="mono">{{ $ticket->number }} · {{ $ticket->customer->user->name }} · {{ $ticket->customer->user->email }}</p>
        </div>
    </div>

    <div class="grid cols-3" style="gap:20px;align-items:start">
        <div class="card" style="grid-column:span 2">
            <div class="thread">
                @foreach ($ticket->replies as $reply)
                    <div class="thread-item {{ $reply->is_staff ? 'is-staff' : 'is-customer' }}">
                        <div class="avatar" style="flex:none">{{ $reply->user?->initials() ?? '?' }}</div>
                        <div style="min-width:0">
                            <div class="row" style="gap:8px;align-items:baseline">
                                <strong>{{ $reply->is_staff ? $reply->user?->name : $ticket->customer->user->name }}</strong>
                                @if ($reply->is_staff)<span class="badge badge-info">Staff</span>@endif
                                <span class="small muted">{{ $reply->created_at->format('d M Y, H:i') }}</span>
                            </div>
                            <div class="thread-body">{!! nl2br(e($reply->body)) !!}</div>
                        </div>
                    </div>
                @endforeach
            </div>

            @can('reply', $ticket)
                <form method="POST" action="{{ route('admin.tickets.reply', $ticket) }}" class="card-body" style="border-top:1px solid var(--line)">
                    @csrf
                    <x-textarea label="Staff reply (visible to the customer)" name="body" rows="4" required />
                    <div class="form-actions">
                        <button class="btn btn-primary">Send reply</button>
                    </div>
                </form>
            @endcan
        </div>

        <div class="card">
            <div class="card-head"><h2>Triage</h2></div>
            @can('update', $ticket)
                <form method="POST" action="{{ route('admin.tickets.update', $ticket) }}" class="card-body">
                    @csrf @method('PATCH')
                    <x-select label="Status" name="status" :value="$ticket->status" required>
                        @foreach (['open' => 'Open', 'pending' => 'Awaiting customer', 'resolved' => 'Resolved', 'closed' => 'Closed'] as $v => $l)
                            <option value="{{ $v }}" @selected($ticket->status === $v)>{{ $l }}</option>
                        @endforeach
                    </x-select>
                    <x-select label="Priority" name="priority" :value="$ticket->priority" required>
                        @foreach (['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'] as $v => $l)
                            <option value="{{ $v }}" @selected($ticket->priority === $v)>{{ $l }}</option>
                        @endforeach
                    </x-select>
                    <x-select label="Assigned to" name="assigned_to" :value="$ticket->assigned_to">
                        <option value="">Unassigned</option>
                        @foreach ($staff as $member)
                            <option value="{{ $member->id }}" @selected($ticket->assigned_to === $member->id)>{{ $member->name }}</option>
                        @endforeach
                    </x-select>
                    <button class="btn btn-primary btn-block">Save</button>
                </form>
            @else
                <div class="card-body small muted">You can reply but not re-triage this ticket.</div>
            @endcan

            <div class="card-body" style="border-top:1px solid var(--line)">
                <div class="kv"><span>Customer</span><span>{{ $ticket->customer->user->name }}</span></div>
                <div class="kv"><span>Category</span><span>{{ ucfirst($ticket->category) }}</span></div>
                <div class="kv"><span>Licence</span><span class="mono">{{ $ticket->license_id ? '#'.$ticket->license_id : '—' }}</span></div>
                <div class="kv"><span>Opened</span><span>{{ $ticket->created_at->format('d M Y') }}</span></div>
            </div>
        </div>
    </div>
</x-app-layout>
