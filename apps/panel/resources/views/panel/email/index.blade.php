@extends('layouts.app')

@section('title', 'Email')

@section('content')
    <h1>Email accounts</h1>
    <p class="small muted">
        Mail is served by Dovecot over IMAP and Postfix over SMTP.
        @if ($limit) Your package allows {{ $limit }} mailbox(es). @endif
    </p>

    <div class="card">
        <div class="card-head"><h2>Create a mailbox</h2></div>

        <form method="POST" action="{{ route('panel.email.store') }}">
            @csrf

            <div class="form-row cols">
                <div>
                    <label for="local_part">Address</label>
                    <input id="local_part" name="local_part" type="text" value="{{ old('local_part') }}" placeholder="orders" required>
                </div>
                <div>
                    <label for="domain">Domain</label>
                    <select id="domain" name="domain">
                        @foreach ($domains as $domain)
                            <option value="{{ $domain }}" @selected(old('domain') === $domain)>{{ $domain }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="form-row cols">
                <div>
                    <label for="password">Password</label>
                    <input id="password" name="password" type="text" required autocomplete="new-password">
                    <div class="hint">12+ characters, mixed case, at least one number. Shown to you now; stored hashed.</div>
                </div>
                <div>
                    <label for="quota_mb">Mailbox quota (MB)</label>
                    <input id="quota_mb" name="quota_mb" type="number" min="50" max="51200" value="{{ old('quota_mb', 1024) }}" required>
                </div>
            </div>

            <button class="btn primary" type="submit"><x-icon name="plus" :size="15" /> Create mailbox</button>
        </form>
    </div>

    <div class="card">
        <h2>Mailboxes</h2>

        @if ($mailboxes->isEmpty())
            <div class="empty"><h3>No mailboxes</h3><p>Create a mailbox, then sign in at webmail with that address.</p></div>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Address</th><th>Quota</th><th>Created</th><th class="right">Actions</th></tr></thead>
                    <tbody>
                        @foreach ($mailboxes as $mailbox)
                            <tr>
                                <td><strong>{{ $mailbox->address }}</strong></td>
                                <td class="small">{{ number_format($mailbox->quota_mb) }} MB</td>
                                <td class="small faint">{{ $mailbox->created_at?->format('j M Y') }}</td>
                                <td class="right">
                                    <a class="btn sm" href="{{ route('webmail.inbox') }}">Open webmail</a>

                                    <form method="POST" action="{{ route('panel.email.password', $mailbox) }}" class="inline-form">
                                        @csrf
                                        <button class="btn sm" type="submit"><x-icon name="key" :size="14" /> New password</button>
                                    </form>

                                    <form method="POST" action="{{ route('panel.email.destroy', $mailbox) }}" class="inline-form"
                                          onsubmit="return confirm('Delete {{ $mailbox->address }} and its mail?')">
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
