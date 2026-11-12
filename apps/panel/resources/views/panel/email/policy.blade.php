@extends('layouts.app')

@section('title', 'Mail policy')

@section('content')
    <div class="subnav">
        <a href="{{ route('panel.email.index') }}">Accounts</a>
        <a href="{{ route('panel.email.policy') }}" class="active">Routing &amp; policy</a>
        <a href="{{ route('panel.email.filters') }}">Filters</a>
        <a href="{{ route('webmail.inbox') }}">Webmail</a>
    </div>

    <h1>Mail routing and policy</h1>
    <p class="small muted">What happens to a message after it is accepted: where it is forwarded, who gets an automatic reply, and how spam is scored.</p>

    @if (session('dkim'))
        <div class="card" style="margin-bottom:16px">
            <h2 style="margin-top:0">DKIM DNS record</h2>
            <p class="small muted">Publish this TXT record at your DNS provider, then re-check with your spam tester.</p>
            <dl class="kv-grid">
                <dt>Name</dt><dd class="mono small">{{ session('dkim')['record_name'] }}</dd>
                <dt>Type</dt><dd class="mono small">TXT</dd>
                <dt>Value</dt><dd class="mono small" style="word-break:break-all">{{ session('dkim')['record_value'] }}</dd>
            </dl>
        </div>
    @endif

    <div class="grid two" style="margin-bottom:16px">
        {{-- forwarders --}}
        <div class="card">
            <h2 style="margin-top:0">Forwarders</h2>
            <p class="small muted">Send mail for one address to one or more others.</p>

            @if ($forwarders->isNotEmpty())
                <div class="table-wrap" style="margin-bottom:12px">
                    <table>
                        <thead><tr><th>From</th><th>To</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($forwarders as $fwd)
                                <tr>
                                    <td class="mono small">{{ $fwd->source }}&#64;{{ $fwd->domain?->domain }}</td>
                                    <td class="small">{{ implode(', ', $fwd->destinations()) }}</td>
                                    <td style="text-align:right">
                                        <form method="POST" action="{{ route('panel.email.forwarders.destroy', $fwd) }}" class="inline-form"
                                              data-confirm="Remove this forwarder?">
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

            <form method="POST" action="{{ route('panel.email.forwarders.store') }}">
                @csrf
                <div class="form-row">
                    <label for="fw-domain">Domain</label>
                    <select id="fw-domain" name="domain_id">
                        @foreach ($domains as $domain)
                            <option value="{{ $domain->id }}">{{ $domain->domain }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row">
                    <label for="fw-source">Source</label>
                    <input id="fw-source" name="source" type="text" value="{{ old('source') }}" placeholder="sales" required>
                    <span class="hint">A local part only. Use * for a catch-all.</span>
                    @error('source') <span class="error-text">{{ $message }}</span> @enderror
                </div>
                <div class="form-row">
                    <label for="fw-dest">Destinations</label>
                    <textarea id="fw-dest" name="destination" rows="2" placeholder="ops@example.net, ceo@example.org">{{ old('destination') }}</textarea>
                    @error('destinations') <span class="error-text">{{ $message }}</span> @enderror
                </div>
                <label class="checkbox"><input type="checkbox" name="keep_copy" value="1"> Also keep a copy locally</label>
                <div class="form-actions">
                    <button class="btn primary" type="submit"><x-icon name="forward" :size="15" /> Add forwarder</button>
                </div>
            </form>
        </div>

        {{-- autoresponders --}}
        <div class="card">
            <h2 style="margin-top:0">Autoresponders</h2>
            <p class="small muted">A vacation message, optionally bounded by dates.</p>

            @if ($autoresponders->isNotEmpty())
                <div class="table-wrap" style="margin-bottom:12px">
                    <table>
                        <thead><tr><th>Mailbox</th><th>Subject</th><th>Running</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($autoresponders as $auto)
                                <tr>
                                    <td class="mono small">{{ $auto->mailbox }}&#64;{{ $auto->domain?->domain }}</td>
                                    <td class="small">{{ $auto->subject }}</td>
                                    <td><span class="badge {{ $auto->isRunning() ? 'ok' : '' }}">{{ $auto->isRunning() ? 'yes' : 'no' }}</span></td>
                                    <td style="text-align:right">
                                        <form method="POST" action="{{ route('panel.email.autoresponders.destroy', $auto) }}" class="inline-form"
                                              data-confirm="Remove this autoresponder?">
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

            <form method="POST" action="{{ route('panel.email.autoresponders.store') }}">
                @csrf
                <div class="form-row">
                    <label for="ar-domain">Domain</label>
                    <select id="ar-domain" name="domain_id">
                        @foreach ($domains as $domain)
                            <option value="{{ $domain->id }}">{{ $domain->domain }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row">
                    <label for="ar-mailbox">Mailbox</label>
                    <input id="ar-mailbox" name="mailbox" type="text" value="{{ old('mailbox') }}" placeholder="team" required>
                </div>
                <div class="form-row">
                    <label for="ar-subject">Subject</label>
                    <input id="ar-subject" name="subject" type="text" value="{{ old('subject', 'Out of office') }}" required>
                </div>
                <div class="form-row">
                    <label for="ar-body">Message</label>
                    <textarea id="ar-body" name="body" rows="3" required>{{ old('body') }}</textarea>
                </div>
                <div class="inline-pair">
                    <div class="form-row">
                        <label for="ar-start">Starts (optional)</label>
                        <input id="ar-start" name="starts_at" type="datetime-local" value="{{ old('starts_at') }}">
                    </div>
                    <div class="form-row">
                        <label for="ar-end">Ends (optional)</label>
                        <input id="ar-end" name="ends_at" type="datetime-local" value="{{ old('ends_at') }}">
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn primary" type="submit"><x-icon name="clock" :size="15" /> Set autoresponder</button>
                </div>
            </form>
        </div>
    </div>

    <div class="grid two">
        {{-- catch-all + policy --}}
        <div class="card">
            <h2 style="margin-top:0">Catch-all and spam policy</h2>

            @foreach ($domains as $domain)
                @php
                    $catch = $catchalls[$domain->id] ?? null;
                    $setting = $settings[$domain->id] ?? null;
                @endphp

                <div style="padding:12px 0; border-bottom:1px solid var(--line, #232a36)">
                    <div class="spread" style="margin-bottom:8px">
                        <strong class="small">{{ $domain->domain }}</strong>
                        @if ($catch)
                            <span class="badge accent">catch-all → {{ $catch->destination }}</span>
                        @else
                            <span class="badge">no catch-all</span>
                        @endif
                    </div>

                    <form method="POST" action="{{ route('panel.email.catchall.store') }}" class="inline-form" style="margin-bottom:8px">
                        @csrf
                        <input type="hidden" name="domain_id" value="{{ $domain->id }}">
                        <input name="destination" type="text" value="{{ $catch?->destination }}"
                               placeholder="catchall@example.com or :fail:" style="max-width:260px">
                        <button class="btn ghost sm" type="submit">Save catch-all</button>
                    </form>

                    @if ($catch)
                        <form method="POST" action="{{ route('panel.email.catchall.destroy', $domain->id) }}" class="inline-form"
                              style="margin-bottom:8px" data-confirm="Remove the catch-all for {{ $domain->domain }}?">
                            @csrf @method('DELETE')
                            <button class="btn ghost sm" type="submit">Clear</button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('panel.email.settings.store') }}">
                        @csrf
                        <input type="hidden" name="domain_id" value="{{ $domain->id }}">
                        <div class="inline-pair">
                            <div class="form-row">
                                <label class="small">Spam score threshold</label>
                                <input name="spam_threshold" type="number" step="0.1" min="0" max="20"
                                       value="{{ $setting?->spam_threshold ?? '5.0' }}">
                            </div>
                            <div class="form-row">
                                <label class="small">When exceeded</label>
                                <select name="spam_action">
                                    <option value="mark" @selected(($setting?->spam_action ?? 'mark') === 'mark')>Mark subject</option>
                                    <option value="discard" @selected(($setting?->spam_action) === 'discard')>Discard</option>
                                    <option value="move" @selected(($setting?->spam_action) === 'move')>Move to Junk</option>
                                </select>
                            </div>
                        </div>
                        <input type="hidden" name="mailbox_quota_mb" value="{{ $setting?->mailbox_quota_mb ?? 1024 }}">
                        <label class="checkbox"><input type="checkbox" name="dkim_enabled" value="1" @checked($setting?->dkim_enabled ?? true)> Sign outbound mail (DKIM)</label>
                        <label class="checkbox"><input type="checkbox" name="spf_enabled" value="1" @checked($setting?->spf_enabled ?? true)> Enforce SPF</label>
                        <label class="checkbox"><input type="checkbox" name="greylisting" value="1" @checked($setting?->greylisting ?? false)> Greylisting</label>
                        <div class="form-actions">
                            <button class="btn ghost sm" type="submit">Save policy</button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('panel.email.dkim', $domain->id) }}" class="inline-form"
                          data-confirm="Generate a new DKIM key for {{ $domain->domain }}?">
                        @csrf
                        <button class="btn ghost sm" type="submit"><x-icon name="key" :size="14" /> Generate DKIM key</button>
                    </form>
                </div>
            @endforeach
        </div>

        {{-- mailing lists --}}
        <div class="card">
            <h2 style="margin-top:0">Mailing lists</h2>

            @if ($lists->isNotEmpty())
                <div class="table-wrap" style="margin-bottom:12px">
                    <table>
                        <thead><tr><th>Address</th><th>Members</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($lists as $list)
                                <tr>
                                    <td class="mono small">{{ $list->address() }}</td>
                                    <td class="small">{{ number_format($list->subscribers) }}</td>
                                    <td style="text-align:right">
                                        <form method="POST" action="{{ route('panel.email.lists.destroy', $list) }}" class="inline-form"
                                              data-confirm="Delete {{ $list->address() }}?">
                                            @csrf @method('DELETE')
                                            <button class="btn ghost sm" type="submit"><x-icon name="trash" :size="14" /></button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="small faint">No lists yet.</p>
            @endif

            <form method="POST" action="{{ route('panel.email.lists.store') }}">
                @csrf
                <div class="form-row">
                    <label for="ml-domain">Domain</label>
                    <select id="ml-domain" name="domain_id">
                        @foreach ($domains as $domain)
                            <option value="{{ $domain->id }}">{{ $domain->domain }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="inline-pair">
                    <div class="form-row">
                        <label for="ml-name">List name</label>
                        <input id="ml-name" name="name" type="text" value="{{ old('name') }}" placeholder="announce" required>
                    </div>
                    <div class="form-row">
                        <label for="ml-title">Title (optional)</label>
                        <input id="ml-title" name="title" type="text" value="{{ old('title') }}" placeholder="Announcements">
                    </div>
                </div>
                <div class="form-row">
                    <label for="ml-members">Members</label>
                    <textarea id="ml-members" name="members" rows="3" placeholder="a@example.com, b@example.org">{{ old('members') }}</textarea>
                </div>
                <div class="form-actions">
                    <button class="btn primary" type="submit"><x-icon name="users" :size="15" /> Create list</button>
                </div>
            </form>
        </div>
    </div>
@endsection
