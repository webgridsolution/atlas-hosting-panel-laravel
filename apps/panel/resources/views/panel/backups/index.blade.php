@extends('layouts.app')

@section('title', 'Backups')

@section('content')
    <h1>Backups</h1>
    <p class="small muted">A backup archives your home directory (and databases when asked) to the server's backup volume.</p>

    <div class="card">
        <div class="card-head">
            <div><h2>Run a backup now</h2><p>Large accounts take a little while; the page reloads when it finishes.</p></div>
        </div>

        <form method="POST" action="{{ route('panel.backups.store') }}" class="row">
            @csrf
            <label class="checkbox">
                <input type="checkbox" name="include_databases" value="1" checked>
                Include databases
            </label>
            <button class="btn primary" type="submit"><x-icon name="download" :size="15" /> Start backup</button>
        </form>
    </div>

    <div class="card">
        <h2>History</h2>

        @if ($backups->isEmpty())
            <div class="empty"><h3>No backups yet</h3><p>Run your first backup above.</p></div>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Started</th><th>Archive</th><th>Size</th><th>Status</th><th>Restored</th><th class="right">Actions</th></tr></thead>
                    <tbody>
                        @foreach ($backups as $backup)
                            <tr>
                                <td class="small">{{ $backup->created_at?->format('j M Y, H:i') }}</td>
                                <td class="mono small faint" style="word-break:break-all">{{ $backup->archive ?? '—' }}</td>
                                <td class="small">{{ $backup->bytes !== null ? $backup->sizeLabel() : '—' }}</td>
                                <td>
                                    <span class="badge {{ $backup->status === 'complete' ? 'ok' : ($backup->status === 'failed' ? 'danger' : 'warn') }}">
                                        {{ $backup->status }}
                                    </span>
                                    @if ($backup->detail)
                                        <div class="small faint">{{ \Illuminate\Support\Str::limit($backup->detail, 60) }}</div>
                                    @endif
                                </td>
                                <td class="small faint">{{ $backup->restored_at?->diffForHumans() ?? '—' }}</td>
                                <td class="right">
                                    @if ($backup->status === 'complete')
                                        <form method="POST" action="{{ route('panel.backups.restore', $backup) }}" class="inline-form"
                                              onsubmit="return confirm('Restore this backup? Current files will be replaced.')">
                                            @csrf
                                            <button class="btn sm" type="submit"><x-icon name="refresh" :size="14" /> Restore</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
