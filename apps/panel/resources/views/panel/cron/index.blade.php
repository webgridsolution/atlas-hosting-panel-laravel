@extends('layouts.app')

@section('title', 'Cron jobs')

@section('content')
    <h1>Cron jobs</h1>
    <p class="small muted">Commands run as your account user, never as root. The whole crontab is rewritten on every change.</p>

    <div class="card">
        <div class="card-head"><h2>Schedule a command</h2></div>

        <form method="POST" action="{{ route('panel.cron.store') }}">
            @csrf

            <div class="form-row cols">
                <div>
                    <label for="schedule">Schedule</label>
                    <input id="schedule" name="schedule" type="text" value="{{ old('schedule', '0 3 * * *') }}" required>
                    <div class="hint">Five fields, or @daily, @hourly, @weekly, @monthly, @yearly, @reboot.</div>
                </div>
                <div>
                    <label for="description">Description (optional)</label>
                    <input id="description" name="description" type="text" value="{{ old('description') }}" placeholder="Nightly backup">
                </div>
            </div>

            <div class="form-row">
                <div>
                    <label for="command">Command</label>
                    <input id="command" name="command" type="text" value="{{ old('command') }}"
                           placeholder="/usr/bin/php /home/{{ $account->username }}/public_html/cron.php" required>
                    <div class="hint">Runs with your home directory as the working directory.</div>
                </div>
            </div>

            <button class="btn primary" type="submit"><x-icon name="plus" :size="15" /> Install job</button>
        </form>
    </div>

    <div class="card">
        <h2>Installed jobs</h2>

        @if ($jobs->isEmpty())
            <div class="empty"><h3>No cron jobs</h3><p>Scheduled tasks appear here once installed.</p></div>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Schedule</th><th>Command</th><th>Description</th><th class="right"></th></tr></thead>
                    <tbody>
                        @foreach ($jobs as $job)
                            <tr>
                                <td class="mono small">{{ $job->schedule }}</td>
                                <td class="mono small" style="word-break:break-all">{{ $job->command }}</td>
                                <td class="small">{{ $job->description ?? '—' }}</td>
                                <td class="right">
                                    <form method="POST" action="{{ route('panel.cron.destroy', $job) }}" class="inline-form"
                                          onsubmit="return confirm('Remove this cron job?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn sm danger" type="submit"><x-icon name="trash" :size="14" /></button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
