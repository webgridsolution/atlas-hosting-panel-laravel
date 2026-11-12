@extends('layouts.app')

@section('title', 'Hosting accounts')

@section('content')
    <div class="spread" style="margin-bottom:18px">
        <div>
            <h1>Hosting accounts</h1>
            <p class="small muted">Each account is a separate Linux user with its own home directory, PHP-FPM pool and quota.</p>
        </div>
        <a class="btn primary" href="{{ route('whm.accounts.create') }}"><x-icon name="plus" :size="15" /> New account</a>
    </div>

    <div class="card">
        <form method="GET" action="{{ route('whm.accounts.index') }}" class="row" style="margin-bottom:16px">
            <input type="search" name="q" value="{{ $search }}" placeholder="Search username or domain" style="max-width:280px">
            <select name="status" style="max-width:170px">
                <option value="">Any status</option>
                @foreach (['active', 'suspended', 'pending'] as $option)
                    <option value="{{ $option }}" @selected($status === $option)>{{ ucfirst($option) }}</option>
                @endforeach
            </select>
            <button class="btn" type="submit"><x-icon name="search" :size="15" /> Filter</button>
            @if ($search !== '' || $status !== '')
                <a class="btn ghost" href="{{ route('whm.accounts.index') }}">Clear</a>
            @endif
        </form>

        @if ($accounts->isEmpty())
            <div class="empty">
                <h3>No accounts match</h3>
                <p>Create the first hosting account, or clear the filters.</p>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Account</th><th>Primary domain</th><th>Package</th>
                            <th>Disk</th><th>Status</th><th class="right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($accounts as $account)
                            <tr>
                                <td>
                                    <a href="{{ route('whm.accounts.show', $account) }}"><strong>{{ $account->username }}</strong></a>
                                    <div class="faint small">{{ $account->user?->email ?? 'no panel login' }}</div>
                                </td>
                                <td>{{ $account->domain }}</td>
                                <td class="small">{{ $account->package?->name ?? '—' }}</td>
                                <td class="small">{{ number_format($account->disk_mb / 1024, $account->disk_mb % 1024 === 0 ? 0 : 1) }} GB</td>
                                <td>
                                    <span class="badge {{ $account->status === 'active' ? 'ok' : ($account->status === 'suspended' ? 'warn' : 'accent') }}">
                                        {{ $account->status }}
                                    </span>
                                </td>
                                <td class="right">
                                    <a class="btn sm" href="{{ route('whm.accounts.show', $account) }}">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $accounts->links() }}
        @endif
    </div>
@endsection
