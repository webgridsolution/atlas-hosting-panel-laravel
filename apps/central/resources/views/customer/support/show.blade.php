<x-app-layout :title="$ticket->subject" :crumbs="[['label' => 'Support', 'url' => route('support.index')], ['label' => $ticket->number]]">
    <div class="page-head">
        <div>
            <div class="row" style="gap:10px">
                <h1>{{ $ticket->subject }}</h1>
                <span class="badge {{ $ticket->statusBadge() }}">{{ ucfirst($ticket->status) }}</span>
            </div>
            <p class="mono">{{ $ticket->number }} · {{ ucfirst($ticket->category) }} · {{ ucfirst($ticket->priority) }} priority</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('support.index') }}" class="btn btn-ghost">All tickets</a>
        </div>
    </div>

    <div class="card" style="max-width:860px">
        <div class="thread">
            @foreach ($ticket->replies as $reply)
                <div class="thread-item {{ $reply->is_staff ? 'is-staff' : 'is-customer' }}">
                    <div class="avatar" style="flex:none">{{ $reply->user?->initials() ?? '?' }}</div>
                    <div style="min-width:0">
                        <div class="row" style="gap:8px;align-items:baseline">
                            <strong>{{ $reply->is_staff ? 'Atlas support' : $reply->user?->name }}</strong>
                            @if ($reply->is_staff)<span class="badge badge-info">Staff</span>@endif
                            <span class="small muted">{{ $reply->created_at->format('d M Y, H:i') }}</span>
                        </div>
                        <div class="thread-body">{!! nl2br(e($reply->body)) !!}</div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($ticket->status === 'closed')
            <div class="card-body" style="border-top:1px solid var(--line)">
                <x-alert variant="info">This ticket is closed. Open a new ticket if the issue returns.</x-alert>
            </div>
        @else
            <form method="POST" action="{{ route('support.reply', $ticket) }}" class="card-body" style="border-top:1px solid var(--line)">
                @csrf
                <x-textarea label="Reply" name="body" rows="4" required placeholder="Add detail, logs or the outcome of the last suggestion." />
                <div class="form-actions">
                    <button class="btn btn-primary">Send reply</button>
                </div>
            </form>
        @endif
    </div>
</x-app-layout>
