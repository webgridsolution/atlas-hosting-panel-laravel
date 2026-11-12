@extends('layouts.app')

@section('title', 'Webmail')

@section('content')
    <h1>Webmail</h1>
    <p class="small muted">Sign in with a mailbox address and its password — this is separate from your panel login.</p>

    <div class="grid two">
        <div class="card">
            <h2>Sign in to a mailbox</h2>

            <form method="POST" action="{{ route('webmail.login') }}">
                @csrf

                <div class="form-row">
                    <div>
                        <label for="address">Email address</label>
                        <input id="address" name="address" type="email" list="mailbox-list" value="{{ old('address') }}" placeholder="you&#64;{{ $account->domain }}" required>
                        <datalist id="mailbox-list">
                            @foreach ($mailboxes as $mailbox)
                                <option value="{{ $mailbox->address }}">
                            @endforeach
                        </datalist>
                    </div>
                </div>

                <div class="form-row">
                    <div>
                        <label for="password">Password</label>
                        <input id="password" name="password" type="password" required autocomplete="current-password">
                    </div>
                </div>

                <button class="btn primary" type="submit">Open mailbox</button>
            </form>
        </div>

        <div class="card">
            <h2>Your mailboxes</h2>

            @if ($mailboxes->isEmpty())
                <div class="empty">
                    <h3>No mailboxes yet</h3>
                    <p><a href="{{ route('panel.email.index') }}">Create one</a> to use webmail.</p>
                </div>
            @else
                <ul class="small" style="padding-left:18px;color:var(--muted);line-height:2">
                    @foreach ($mailboxes as $mailbox)
                        <li class="mono">{{ $mailbox->address }}</li>
                    @endforeach
                </ul>
                <p class="small faint">Only addresses on this account can sign in here.</p>
            @endif
        </div>
    </div>
@endsection
