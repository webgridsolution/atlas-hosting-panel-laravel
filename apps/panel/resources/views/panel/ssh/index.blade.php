@extends('layouts.app')

@section('title', 'SSH access')

@php
    $activeKeys = collect($keys)->where('authorized', true)->count();
    $port = (int) ($info['port'] ?? 22);
    $host = (string) (config('atlas.server.ip') ?: request()->getHost());
    $hostKeys = $info['host_keys'] ?? [];
@endphp

@section('content')
<div class="spread mb-4">
    <div>
        <h1 class="mb-0">SSH access</h1>
        <p class="small muted mb-0">Log in to this account from your own terminal and work in <span class="mono">{{ $account->home() }}</span>.</p>
    </div>
    <div class="row">
        <span class="badge {{ $enabled ? 'ok' : 'plain' }}">{{ $enabled ? 'Access enabled' : 'Access disabled' }}</span>
        <span class="badge plain accent">{{ $activeKeys }} {{ \Illuminate\Support\Str::plural('key', $activeKeys) }} authorised</span>
    </div>
</div>

{{-- ---- the two facts that matter: is it on, and how do I connect ---- --}}
<div class="ssh-hero mb-4">
    <span class="glyph"><x-icon name="terminal-2" :size="22" /></span>

    <div style="min-width:0;flex:1">
        <h2>{{ $account->username }}{{ '@' }}{{ $host }}</h2>
        <p>
            @if ($enabled)
                Shell logins are allowed. Only authorised keys can get in.
            @else
                Shell logins are refused for this account. Authorise a key, then switch access on.
            @endif
            Port <strong class="mono">{{ $port }}</strong>.
        </p>
    </div>

    <form method="POST" action="{{ route('panel.ssh.access') }}" class="inline-form">
        @csrf
        <input type="hidden" name="enabled" value="{{ $enabled ? 0 : 1 }}">
        <label class="switch" title="{{ $enabled ? 'Switch SSH access off' : 'Switch SSH access on' }}">
            <input type="checkbox" @checked($enabled)
                   onchange="this.form.submit()"
                   aria-label="SSH access for this account">
            <span class="track"></span>
            <span class="label">{{ $enabled ? 'On' : 'Off' }}</span>
        </label>
    </form>
</div>

<div class="grid sidebar-right">
    <div>
        {{-- ---- keys ---- --}}
        <div class="card flush">
            <div class="card-head" style="padding:20px 20px 0">
                <div>
                    <h2>Authorised keys</h2>
                    <p>A key can only log in once it is both listed here and authorised.</p>
                </div>
                <div class="row tight">
                    <button class="btn sm primary" type="button" data-dlg-open="dlg-generate">
                        <x-icon name="key-round" :size="14" /> Generate
                    </button>
                    <button class="btn sm" type="button" data-dlg-open="dlg-import">
                        <x-icon name="upload" :size="14" /> Import
                    </button>
                </div>
            </div>

            @if (count($keys) === 0)
                <div class="empty">
                    <x-icon name="key-round" />
                    <h3>No SSH keys yet</h3>
                    <p>
                        Generate a keypair here and download the private half, or import a public key you
                        already use. A key is useless until you authorise it and switch access on.
                    </p>
                    <button class="btn sm primary" type="button" data-dlg-open="dlg-generate">
                        <x-icon name="key-round" :size="14" /> Generate my first key
                    </button>
                </div>
            @else
                <div class="table-wrap" style="border:0;border-radius:0;margin-top:12px">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th style="width:110px">Type</th>
                                <th style="width:110px">Status</th>
                                <th style="width:170px">Fingerprint</th>
                                <th style="width:130px">Created</th>
                                <th style="width:180px" class="right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach ($keys as $key)
                            <tr>
                                <td>
                                    <div class="fm-row">
                                        <span class="fm-ico {{ ! empty($key['authorized']) ? 'dir' : '' }}">
                                            <x-icon name="key-round" :size="14" />
                                        </span>
                                        <div style="min-width:0">
                                            <div class="fm-name mono">{{ $key['name'] }}</div>
                                            @if (! empty($key['comment']))
                                                <div class="fm-meta truncate">{{ $key['comment'] }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge plain">{{ str_replace('ssh-', '', (string) ($key['type'] ?: 'unknown')) }}</span>
                                    @if (! empty($key['bits']))
                                        <span class="faint xs">{{ $key['bits'] }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if (! empty($key['authorized']))
                                        <span class="badge ok">Authorised</span>
                                    @else
                                        <span class="badge">Not authorised</span>
                                    @endif
                                </td>
                                <td class="mono faint xs" style="word-break:break-all;max-width:170px">
                                    {{ \Illuminate\Support\Str::limit((string) ($key['fingerprint'] ?? ''), 26, '…') ?: '—' }}
                                </td>
                                <td class="faint small nowrap">
                                    {{ ! empty($key['created_at']) ? \Illuminate\Support\Carbon::parse($key['created_at'])->format('d M Y') : '—' }}
                                </td>
                                <td>
                                    <div class="actions">
                                        @if (! empty($key['has_private']))
                                            <a class="btn icon sm ghost" title="Download private key"
                                               href="{{ route('panel.ssh.keys.download', ['name' => $key['name']]) }}">
                                                <x-icon name="download" :size="13" />
                                            </a>
                                        @endif

                                        @if (! empty($key['authorized']))
                                            <form method="POST" action="{{ route('panel.ssh.keys.deauthorize') }}" class="inline-form">
                                                @csrf
                                                <input type="hidden" name="name" value="{{ $key['name'] }}">
                                                <button class="btn sm" type="submit" title="Stop this key logging in">Deauthorise</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('panel.ssh.keys.authorize') }}" class="inline-form">
                                                @csrf
                                                <input type="hidden" name="name" value="{{ $key['name'] }}">
                                                <button class="btn sm primary" type="submit" title="Allow this key to log in">Authorise</button>
                                            </form>
                                        @endif

                                        <form method="POST" action="{{ route('panel.ssh.keys.destroy') }}" class="inline-form"
                                              onsubmit="return confirm('Delete the key {{ $key['name'] }}? This cannot be undone.')">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="name" value="{{ $key['name'] }}">
                                            <button class="btn icon sm ghost danger" type="submit" title="Delete key">
                                                <x-icon name="trash-2" :size="13" />
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- ---- connecting ---- --}}
        <div class="card mt-4">
            <div class="card-head">
                <div>
                    <h2>Connecting</h2>
                    <p>The exact command for this account, built from this server's address and port.</p>
                </div>
            </div>

            <div class="term-block" data-copy-block>{{ $command ?: 'ssh '.$account->username.'@'.$host }}
<button class="copy" type="button" data-copy aria-label="Copy command">
                    <x-icon name="copy" :size="13" />
                </button></div>

            <dl class="kv mt-4 mb-0">
                <dt>Host</dt>
                <dd class="mono">{{ $host }}</dd>

                <dt>Port</dt>
                <dd class="mono">{{ $port }}</dd>

                <dt>Username</dt>
                <dd class="mono">{{ $account->username }}</dd>

                <dt>Shell</dt>
                <dd>
                    @if ($enabled)
                        <span class="badge ok">/bin/bash</span>
                    @else
                        <span class="badge">/usr/sbin/nologin</span>
                    @endif
                </dd>

                <dt>Home directory</dt>
                <dd class="mono">{{ $account->home() }}</dd>

                <dt>Private keys</dt>
                <dd>
                    @if (collect($keys)->where('has_private', true)->count() > 0)
                        Download one from the table above and keep it at <span class="mono">~/.ssh/</span> with
                        permissions <span class="mono">0600</span>.
                    @else
                        Imported keys have no private half here — you already hold it.
                    @endif
                </dd>
            </dl>

            <div class="alert mt-4 mb-0">
                <x-icon name="shield-check" :size="15" />
                <div>
                    <strong>Password logins are never enabled for this account.</strong>
                    The agent gives the account either <span class="mono">/bin/bash</span> or
                    <span class="mono">/usr/sbin/nologin</span>; authentication is by key only, and the panel
                    never stores a private key of its own.
                </div>
            </div>
        </div>
    </div>

    {{-- ---- right rail: the server's side of the story ---- --}}
    <div>
        <div class="card">
            <h3>Server</h3>
            <div class="svc-row">
                {{-- This is the account's shell, not the daemon: sshd runs
                     whether or not this customer may use it. --}}
                <span class="row tight"><span class="dot {{ $enabled ? '' : 'off' }}"></span> Shell login</span>
                <span class="small {{ $enabled ? 'muted' : 'faint' }}">{{ $enabled ? 'allowed' : 'refused' }}</span>
            </div>
            <div class="svc-row">
                <span class="muted small">Port</span>
                <span class="mono small">{{ $port }}</span>
            </div>
            <div class="svc-row">
                <span class="muted small">Algorithms</span>
                <span class="small mono">{{ implode(', ', $info['algorithms'] ?? []) ?: '—' }}</span>
            </div>
        </div>

        <div class="card">
            <h3>Host keys</h3>
            <p class="small muted">Compare these with what your terminal shows on first connect.</p>

            @if (count($hostKeys) === 0)
                <p class="small faint mb-0">
                    This machine has no SSH host keys installed, so there is nothing to show.
                </p>
            @else
                <div class="stack">
                    @foreach ($hostKeys as $hk)
                        <div>
                            <div class="row tight" style="justify-content:space-between">
                                <span class="badge plain">{{ $hk['type'] }}</span>
                            </div>
                            <div class="key-fingerprint mt-1" style="margin-top:6px">{{ $hk['fingerprint'] ?: 'not readable' }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="card">
            <h3>Firewall</h3>
            <p class="small muted mb-0">
                SSH should only be reachable from addresses you control. Allowlist your own IP in
                <a href="{{ route('panel.security.index') }}">Security</a> before you enable access on a
                public server.
            </p>
        </div>

        <div class="card">
            <h3>Related</h3>
            <div class="stack">
                <a class="row tight" href="{{ route('panel.files.index') }}" style="justify-content:flex-start">
                    <x-icon name="folder-open" :size="14" /> File manager
                </a>
                <a class="row tight" href="{{ route('panel.ftp.index') }}" style="justify-content:flex-start">
                    <x-icon name="plug" :size="14" /> FTP accounts
                </a>
                <a class="row tight" href="{{ route('panel.usage.logins') }}" style="justify-content:flex-start">
                    <x-icon name="receipt" :size="14" /> Login log
                </a>
            </div>
        </div>
    </div>
</div>

{{-- ---- generate ---- --}}
<div class="dlg" id="dlg-generate" role="dialog" aria-modal="true" aria-labelledby="dlg-gen-h">
    <div class="dlg-card">
        <h3 id="dlg-gen-h">Generate a keypair</h3>
        <p class="small muted">
            The private key is written to <span class="mono">~/.ssh/</span> and never stored by the panel.
            Download it immediately — it is not shown again.
        </p>

        <form method="POST" action="{{ route('panel.ssh.keys.store') }}">
            @csrf
            <div class="form-row two">
                <div>
                    <label for="gen-name">Key name</label>
                    <input id="gen-name" name="name" type="text" required maxlength="64"
                           value="id_ed25519" pattern="[A-Za-z0-9][A-Za-z0-9._-]*" autocomplete="off">
                    <div class="field-hint">Saved as <span class="mono">~/.ssh/&lt;name&gt;</span>.</div>
                </div>
                <div>
                    <label for="gen-type">Type</label>
                    <select id="gen-type" name="type">
                        <option value="ed25519">Ed25519 — recommended</option>
                        <option value="rsa">RSA 4096 — for old clients</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div>
                    <label for="gen-comment">Comment</label>
                    <input id="gen-comment" name="comment" type="text" maxlength="128"
                           placeholder="laptop · 2026" autocomplete="off">
                    <div class="field-hint">Written into the public key so you can tell keys apart.</div>
                </div>
            </div>

            <div class="dlg-foot">
                <button class="btn sm" type="button" data-dlg-close>Cancel</button>
                <button class="btn sm primary" type="submit"><x-icon name="key-round" :size="14" /> Generate</button>
            </div>
        </form>
    </div>
</div>

{{-- ---- import ---- --}}
<div class="dlg" id="dlg-import" role="dialog" aria-modal="true" aria-labelledby="dlg-imp-h">
    <div class="dlg-card">
        <h3 id="dlg-imp-h">Import a public key</h3>
        <p class="small muted">
            Paste the contents of your <span class="mono">id_ed25519.pub</span> (or <span class="mono">id_rsa.pub</span>).
            The agent checks it with <span class="mono">ssh-keygen</span> before accepting it.
        </p>

        <form method="POST" action="{{ route('panel.ssh.keys.store') }}">
            @csrf
            <div class="form-row two">
                <div>
                    <label for="imp-name">Key name</label>
                    <input id="imp-name" name="name" type="text" required maxlength="64"
                           value="work-laptop" pattern="[A-Za-z0-9][A-Za-z0-9._-]*" autocomplete="off">
                </div>
                <div></div>
            </div>

            <div class="form-row">
                <div>
                    <label for="imp-public">Public key</label>
                    <textarea id="imp-public" name="public" required maxlength="8192" rows="4"
                              placeholder="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI... you@laptop"
                              style="font-family:var(--mono);font-size:12px"></textarea>
                    <div class="field-hint">One key per import. Type must be ed25519, rsa, ecdsa or sk-*.</div>
                </div>
            </div>

            <div class="dlg-foot">
                <button class="btn sm" type="button" data-dlg-close>Cancel</button>
                <button class="btn sm primary" type="submit"><x-icon name="upload" :size="14" /> Import</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
/* Copy-to-clipboard for the connection command, and dialog plumbing shared with
   the file manager. Kept inline because it is nine lines. */
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-dlg-open]');
        if (opener) {
            var dlg = document.getElementById(opener.dataset.dlgOpen);
            if (dlg) {
                dlg.classList.add('on');
                var first = dlg.querySelector('input:not([type=hidden])');
                if (first) { first.focus(); }
            }
            return;
        }

        var closer = e.target.closest('[data-dlg-close]');
        if (closer) {
            closer.closest('.dlg').classList.remove('on');
            return;
        }

        if (e.target.classList.contains('dlg')) {
            e.target.classList.remove('on');
            return;
        }

        var copy = e.target.closest('[data-copy]');
        if (copy) {
            var block = copy.closest('[data-copy-block]');
            var text = block ? block.textContent.replace(/\s*$/, '') : '';
            var done = function () {
                copy.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
                                 'stroke-linecap="round" stroke-linejoin="round"><path d="m4 12 5 5L20 6"/></svg>';
                setTimeout(function () { window.location.reload(); }, 900);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done, function () {});
            } else {
                var ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); done(); } catch (err) {}
                document.body.removeChild(ta);
            }
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        document.querySelectorAll('.dlg.on').forEach(function (d) { d.classList.remove('on'); });
    });
})();
</script>
@endpush
