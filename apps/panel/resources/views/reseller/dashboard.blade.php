@extends('layouts.app')

@section('title', 'Reseller overview')

@section('content')
    <div class="spread" style="margin-bottom:18px">
        <div>
            <h1>Reseller overview</h1>
            <p class="small muted">You manage your own customers. The server itself stays with the administrator.</p>
        </div>
        <a class="btn primary" href="{{ route('reseller.accounts.create') }}"><x-icon name="plus" :size="15" /> New customer</a>
    </div>

    @if ($licence['state'] !== 'active')
        <div class="license-bar {{ $licence['state'] === 'read_only' ? 'danger' : '' }}">
            <x-icon name="alert" :size="16" />
            <span>{{ $licence['reason'] }}</span>
        </div>
    @endif

    <div class="grid four" style="margin-bottom:18px">
        <div class="stat"><div class="label">Customers</div><div class="value">{{ $stats['accounts'] }}</div><div class="sub">{{ $stats['active'] }} active</div></div>
        <div class="stat"><div class="label">Suspended</div><div class="value">{{ $stats['suspended'] }}</div><div class="sub">data kept</div></div>
        <div class="stat"><div class="label">Allocated disk</div><div class="value">{{ number_format($stats['disk_mb'] / 1024, 1) }}<span class="small faint"> GB</span></div><div class="sub">across your accounts</div></div>
        <div class="stat"><div class="label">Licence</div><div class="value" style="font-size:1.1rem">{{ str_replace('_', ' ', $licence['state']) }}</div><div class="sub">managed by the admin</div></div>
    </div>

    <div class="card">
        <div class="card-head">
            <div><h2>Latest customers</h2><p>Newest accounts you created.</p></div>
            <a class="btn ghost sm" href="{{ route('reseller.accounts.index') }}">All customers</a>
        </div>

        @if ($recent->isEmpty())
            <div class="empty"><h3>No customers yet</h3><p>Create the first account to get started.</p></div>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Account</th><th>Domain</th><th>Package</th><th>Status</th><th class="right"></th></tr></thead>
                    <tbody>
                        @foreach ($recent as $account)
                            <tr>
                                <td><strong>{{ $account->username }}</strong></td>
                                <td>{{ $account->domain }}</td>
                                <td class="small">{{ $account->package?->name ?? '—' }}</td>
                                <td><span class="badge {{ $account->status === 'active' ? 'ok' : 'warn' }}">{{ $account->status }}</span></td>
                                <td class="right"><a class="btn sm" href="{{ route('reseller.accounts.show', $account) }}">Open</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
