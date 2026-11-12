@extends('layouts.app')

@section('title', 'Audit log')

@section('content')
    <h1>Audit log</h1>
    <p class="small muted">
        Every operation the panel sent to the agent, with the operator behind it. The agent keeps its own
        hash-chained journal at <span class="mono">/var/log/atlas/agent-journal.jsonl</span>; this is the panel-side mirror.
    </p>

    <div class="card">
        <form method="GET" action="{{ route('whm.audit.index') }}" class="row" style="margin-bottom:16px">
            <input type="search" name="op" value="{{ $op }}" placeholder="Filter by operation" style="max-width:260px">
            <select name="status" style="max-width:170px">
                <option value="">Any result</option>
                @foreach (['ok', 'error', 'denied'] as $option)
                    <option value="{{ $option }}" @selected($status === $option)>{{ $option }}</option>
                @endforeach
            </select>
            <button class="btn" type="submit"><x-icon name="search" :size="15" /> Filter</button>
            <a class="btn ghost" href="{{ route('whm.audit.index') }}">Clear</a>
        </form>

        @if ($operations->isEmpty())
            <div class="empty"><h3>No operations recorded</h3><p>Actions appear here the moment the panel asks the agent to do something.</p></div>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>When</th><th>Operation</th><th>Account</th><th>Operator</th><th>Status</th><th>Detail</th><th class="right">Took</th></tr></thead>
                    <tbody>
                        @foreach ($operations as $operation)
                            <tr>
                                <td class="small faint">{{ $operation->created_at?->format('d M H:i:s') }}</td>
                                <td class="mono small">{{ $operation->op }}</td>
                                <td class="small">{{ $operation->account?->username ?? '—' }}</td>
                                <td class="small">{{ $operation->user?->email ?? 'system' }}</td>
                                <td><span class="badge {{ $operation->status === 'ok' ? 'ok' : ($operation->status === 'denied' ? 'warn' : 'danger') }}">{{ $operation->status }}</span></td>
                                <td class="small faint">{{ \Illuminate\Support\Str::limit($operation->detail, 70) ?: '—' }}</td>
                                <td class="right small faint">{{ $operation->duration_ms }} ms</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $operations->links() }}
        @endif
    </div>
@endsection
