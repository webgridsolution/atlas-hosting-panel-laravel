<x-app-layout title="Audit & security" :crumbs="[['label' => 'Audit & security']]">
    <div class="page-head">
        <div>
            <h1>Audit &amp; security</h1>
            <p>Who did what, and what the platform noticed. Financial and privilege events are recorded automatically.</p>
        </div>
        @if ($unresolved > 0)
            <div class="page-actions"><x-badge variant="danger">{{ $unresolved }} unresolved</x-badge></div>
        @endif
    </div>

    <div class="grid cols-2" style="gap:20px;align-items:start">
        <div class="card">
            <div class="card-head">
                <h2>Security events</h2>
                <form method="GET" class="row" style="gap:6px">
                    <input type="hidden" name="action" value="{{ request('action') }}">
                </form>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>When</th><th>Event</th><th>Severity</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($events as $event)
                            <tr>
                                <td class="num small">{{ $event->created_at->format('d M H:i') }}</td>
                                <td>
                                    <span class="cell-strong mono small">{{ $event->type }}</span>
                                    <span class="cell-sub mono">{{ \Illuminate\Support\Str::limit((string) json_encode($event->meta), 70) }}</span>
                                </td>
                                <td>
                                    <x-badge :variant="match($event->severity) { 'critical' => 'danger', 'high' => 'warn', 'medium' => 'info', default => 'neutral' }">
                                        {{ ucfirst($event->severity) }}
                                    </x-badge>
                                </td>
                                <td class="text-right">
                                    @if ($event->resolved_at)
                                        <span class="small muted">Resolved</span>
                                    @else
                                        <form method="POST" action="{{ route('admin.audit.resolve', $event) }}">
                                            @csrf @method('PATCH')
                                            <button class="btn btn-ghost btn-sm">Resolve</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center muted" style="padding:24px">Nothing flagged.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($events->hasPages()) {{ $events->links() }} @endif
        </div>

        <div class="card">
            <div class="card-head">
                <h2>Audit log</h2>
                <form method="GET" class="row" style="gap:6px">
                    <input class="input input-sm" name="action" value="{{ request('action') }}" placeholder="filter action…" style="max-width:180px">
                    <button class="btn btn-secondary btn-sm">Filter</button>
                </form>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>When</th><th>Action</th><th>Actor</th><th>Subject</th></tr></thead>
                    <tbody>
                        @forelse ($logs as $log)
                            <tr>
                                <td class="num small">{{ $log->created_at->format('d M H:i') }}</td>
                                <td class="mono small cell-strong">{{ $log->action }}</td>
                                <td class="small">{{ $log->actorLabel() }}</td>
                                <td class="small muted mono">{{ class_basename($log->subject_type) }} {{ $log->subject_id }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center muted" style="padding:24px">No entries yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($logs->hasPages()) {{ $logs->links() }} @endif
        </div>
    </div>
</x-app-layout>
