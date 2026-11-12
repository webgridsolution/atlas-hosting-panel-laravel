@extends('layouts.app')

@section('title', 'Login log')

@section('content')
    <h1>Login log</h1>
    <p class="small muted">Every successful and failed sign-in to this account, newest first. Failed attempts are kept so that a password-guessing run is visible.</p>

    <div class="card">
        @if ($events->isEmpty())
            <div class="empty"><h3>No sign-ins recorded yet</h3><p>This list fills in from now on.</p></div>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>When</th><th>Result</th><th>Area</th><th>IP</th><th>Client</th></tr></thead>
                    <tbody>
                        @foreach ($events as $event)
                            <tr>
                                <td class="small">{{ $event->created_at?->format('j M Y H:i') }}</td>
                                <td>
                                    <span class="badge {{ $event->successful ? 'ok' : 'danger' }}">
                                        {{ $event->successful ? 'success' : 'failed' }}
                                    </span>
                                </td>
                                <td class="small">{{ $event->area }}</td>
                                <td class="mono small">{{ $event->ip }}</td>
                                <td class="small faint" style="max-width:340px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap">
                                    {{ $event->user_agent }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="margin-top:14px">{{ $events->links() }}</div>
        @endif
    </div>
@endsection
