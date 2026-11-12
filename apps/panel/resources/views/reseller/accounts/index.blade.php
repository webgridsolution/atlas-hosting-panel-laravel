@extends('layouts.app')

@section('title', 'My customers')

@section('content')
    <div class="spread" style="margin-bottom:18px">
        <div>
            <h1>My customers</h1>
            <p class="small muted">Only accounts you created appear here.</p>
        </div>
        <a class="btn primary" href="{{ route('reseller.accounts.create') }}"><x-icon name="plus" :size="15" /> New customer</a>
    </div>

    <div class="card">
        <form method="GET" action="{{ route('reseller.accounts.index') }}" class="row" style="margin-bottom:16px">
            <input type="search" name="q" value="{{ $search }}" placeholder="Search username or domain" style="max-width:280px">
            <select name="status" style="max-width:170px">
                <option value="">Any status</option>
                @foreach (['active', 'suspended', 'pending'] as $option)
                    <option value="{{ $option }}" @selected($status === $option)>{{ ucfirst($option) }}</option>
                @endforeach
            </select>
            <button class="btn" type="submit"><x-icon name="search" :size="15" /> Filter</button>
            @if ($search !== '' || $status !== '')
                <a class="btn ghost" href="{{ route('reseller.accounts.index') }}">Clear</a>
            @endif
        </form>

        @if ($accounts->isEmpty())
            <div class="empty">
                <h3>{{ $search !== '' || $status !== '' ? 'No customers match' : 'No customers yet' }}</h3>
                <p>{{ $search !== '' || $status !== '' ? 'Clear the filters to see all of your customers.' : 'Create an account and it will appear here with its own panel login.' }}</p>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Account</th><th>Domain</th><th>Package</th><th>Disk</th><th>Status</th><th class="right">Actions</th></tr></thead>
                    <tbody>
                        @foreach ($accounts as $account)
                            <tr>
                                <td><a href="{{ route('reseller.accounts.show', $account) }}"><strong>{{ $account->username }}</strong></a></td>
                                <td>{{ $account->domain }}</td>
                                <td class="small">{{ $account->package?->name ?? '—' }}</td>
                                <td class="small">{{ number_format($account->disk_mb / 1024, 1) }} GB</td>
                                <td><span class="badge {{ $account->status === 'active' ? 'ok' : ($account->status === 'suspended' ? 'warn' : 'accent') }}">{{ $account->status }}</span></td>
                                <td class="right">
                                    @if ($account->status === 'active')
                                        <form method="POST" action="{{ route('reseller.accounts.suspend', $account) }}" class="inline-form">
                                            @csrf
                                            <button class="btn sm" type="submit">Suspend</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('reseller.accounts.unsuspend', $account) }}" class="inline-form">
                                            @csrf
                                            <button class="btn sm" type="submit">Unsuspend</button>
                                        </form>
                                    @endif
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
