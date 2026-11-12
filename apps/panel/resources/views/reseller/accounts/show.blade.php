@extends('layouts.app')

@section('title', $account->username)

@section('content')
    <div class="spread" style="margin-bottom:18px">
        <div>
            <h1>{{ $account->username }}</h1>
            <p class="small muted">{{ $account->domain }} ·
                <span class="badge {{ $account->status === 'active' ? 'ok' : 'warn' }}">{{ $account->status }}</span>
            </p>
        </div>
        <div class="row">
            @if ($account->status === 'active')
                <form method="POST" action="{{ route('reseller.accounts.suspend', $account) }}" class="inline-form">
                    @csrf
                    <button class="btn danger" type="submit"><x-icon name="lock" :size="15" /> Suspend</button>
                </form>
            @else
                <form method="POST" action="{{ route('reseller.accounts.unsuspend', $account) }}" class="inline-form">
                    @csrf
                    <button class="btn" type="submit">Reactivate</button>
                </form>
            @endif
            <a class="btn ghost" href="{{ route('reseller.accounts.index') }}">Back</a>
        </div>
    </div>

    <div class="grid two">
        <div class="card">
            <h2>Account</h2>
            <dl class="kv">
                <dt>Package</dt><dd>{{ $account->package?->name ?? '—' }}</dd>
                <dt>Disk</dt><dd>{{ number_format($account->disk_mb) }} MB</dd>
                <dt>Inodes</dt><dd>{{ number_format($account->inodes) }}</dd>
                <dt>PHP</dt><dd>{{ $account->php_version }}</dd>
                <dt>Created</dt><dd>{{ $account->created_at?->format('j M Y') }}</dd>
            </dl>
        </div>

        <div class="card">
            <h2>Services in use</h2>
            <dl class="kv">
                <dt>Websites</dt><dd>{{ $account->domains->count() }}</dd>
                <dt>Databases</dt><dd>{{ $account->databases->count() }}</dd>
                <dt>Mailboxes</dt><dd>{{ $account->mailboxes->count() }}</dd>
            </dl>

            <h3 style="margin-top:18px">Domains</h3>
            <ul class="small" style="padding-left:18px;color:var(--muted)">
                @foreach ($account->domains as $domain)
                    <li>{{ $domain->domain }} · {{ $domain->sslLabel() }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endsection
