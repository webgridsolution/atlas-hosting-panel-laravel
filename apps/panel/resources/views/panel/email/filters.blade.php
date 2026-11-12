@extends('layouts.app')

@section('title', 'Email filters')

@section('content')
    <div class="subnav">
        <a href="{{ route('panel.email.index') }}">Accounts</a>
        <a href="{{ route('panel.email.policy') }}">Routing &amp; policy</a>
        <a href="{{ route('panel.email.filters') }}" class="active">Filters</a>
        <a href="{{ route('webmail.inbox') }}">Webmail</a>
    </div>

    <h1>Email filters</h1>
    <p class="small muted">
        Rules are compiled into a Sieve script and run on the mail server, so they work whether or not you are signed in.
        Rules are evaluated in priority order; the first match wins.
    </p>

    <div class="card" style="margin-bottom:16px">
        <h2 style="margin-top:0">Active rules</h2>

        @if ($filters->isEmpty())
            <div class="empty"><h3>No filters</h3><p>Add a rule below to route or discard mail automatically.</p></div>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Mailbox</th><th>Rule</th><th>Priority</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($filters as $filter)
                            <tr>
                                <td class="mono small">{{ $filter->mailbox }}</td>
                                <td class="small">{{ $filter->describe() }}</td>
                                <td class="small faint">{{ $filter->priority }}</td>
                                <td style="text-align:right">
                                    <form method="POST" action="{{ route('panel.email.filters.destroy', $filter) }}" class="inline-form"
                                          data-confirm="Remove this rule?">
                                        @csrf @method('DELETE')
                                        <button class="btn ghost sm" type="submit"><x-icon name="trash" :size="14" /></button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card">
        <h2 style="margin-top:0">New rule</h2>

        <form method="POST" action="{{ route('panel.email.filters.store') }}">
            @csrf
            <div class="inline-pair">
                <div class="form-row">
                    <label for="fl-domain">Domain</label>
                    <select id="fl-domain" name="domain_id">
                        @foreach ($domains as $domain)
                            <option value="{{ $domain->id }}">{{ $domain->domain }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row">
                    <label for="fl-mailbox">Mailbox</label>
                    <input id="fl-mailbox" name="mailbox" type="text" list="mailbox-list"
                           value="{{ old('mailbox', $mailboxes->first()?->address) }}" required>
                    <datalist id="mailbox-list">
                        @foreach ($mailboxes as $mailbox)
                            <option value="{{ $mailbox->address }}">
                        @endforeach
                    </datalist>
                </div>
            </div>

            <div class="form-row">
                <label>When a message matches</label>
                <div class="row" style="gap:8px; flex-wrap:wrap">
                    <select name="field" style="max-width:150px">
                        <option value="from">From</option>
                        <option value="to">To</option>
                        <option value="cc">Cc</option>
                        <option value="subject">Subject</option>
                        <option value="body">Body</option>
                        <option value="x-spam-flag">X-Spam-Flag</option>
                    </select>
                    <select name="operator" style="max-width:150px">
                        <option value="contains">contains</option>
                        <option value="equals">equals</option>
                        <option value="starts">starts with</option>
                        <option value="ends">ends with</option>
                        <option value="matches">matches wildcard</option>
                    </select>
                    <input name="value" type="text" value="{{ old('value') }}" placeholder="value" required style="flex:1">
                </div>
            </div>

            <div class="form-row">
                <label>Then</label>
                <div class="row" style="gap:8px; flex-wrap:wrap">
                    <select name="action" style="max-width:190px">
                        <option value="move">move to folder</option>
                        <option value="discard">discard silently</option>
                        <option value="redirect">redirect to address</option>
                        <option value="mark_spam">mark as spam</option>
                        <option value="keep">keep in inbox</option>
                    </select>
                    <input name="action_arg" type="text" value="{{ old('action_arg') }}"
                           placeholder="folder or address (if needed)" style="flex:1">
                </div>
                @error('action_arg') <span class="error-text">{{ $message }}</span> @enderror
            </div>

            <div class="form-actions">
                <button class="btn primary" type="submit"><x-icon name="filter" :size="15" /> Add rule</button>
            </div>
        </form>
    </div>
@endsection
