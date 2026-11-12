@extends('layouts.app')

@section('title', 'SSL')

@section('content')
    <h1>SSL certificates</h1>
    <p class="small muted">AutoSSL issues a free certificate over ACME. You can also generate a signing request and paste in a certificate you bought elsewhere.</p>

    @if (session('csr'))
        <div class="card" style="margin-bottom:16px">
            <h2 style="margin-top:0">Certificate signing request</h2>
            <p class="small muted">Give this to your certificate authority. The private key stays on the server and is never shown.</p>
            <textarea rows="10" class="mono small" readonly style="width:100%">{{ session('csr') }}</textarea>
        </div>
    @endif

    <div class="card" style="margin-bottom:16px">
        <h2 style="margin-top:0">Certificates</h2>

        @if ($certificates->isEmpty())
            <div class="empty"><h3>No certificates yet</h3><p>Run AutoSSL on a domain below.</p></div>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Common name</th><th>Kind</th><th>Issuer</th><th>Expires</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($certificates as $cert)
                            @php $days = $cert->daysLeft(); @endphp
                            <tr>
                                <td><strong>{{ $cert->common_name }}</strong></td>
                                <td><span class="badge">{{ $cert->kind }}</span></td>
                                <td class="small">{{ $cert->issuer ?? '—' }}</td>
                                <td class="small">
                                    @if ($cert->expires_at)
                                        {{ $cert->expires_at->format('j M Y') }}
                                        @if ($days !== null)
                                            <span class="badge {{ $days < 0 ? 'danger' : ($days < 14 ? 'warn' : 'ok') }}">{{ $days }}d</span>
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td><span class="badge {{ $cert->status === 'active' ? 'ok' : 'warn' }}">{{ $cert->status }}</span></td>
                                <td style="text-align:right">
                                    <form method="POST" action="{{ route('panel.ssl.destroy', $cert) }}" class="inline-form"
                                          data-confirm="Remove this certificate record?">
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

    <div class="grid two">
        <div class="card">
            <h2 style="margin-top:0">AutoSSL</h2>
            <p class="small muted">Issues or renews a Let's Encrypt certificate. The domain's DNS A record must already point here.</p>

            <div class="table-wrap">
                <table>
                    <thead><tr><th>Domain</th><th>Current</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($domains as $domain)
                            <tr>
                                <td class="small"><strong>{{ $domain->domain }}</strong></td>
                                <td><span class="badge {{ $domain->ssl_status === 'active' ? 'ok' : 'warn' }}">{{ $domain->sslLabel() }}</span></td>
                                <td style="text-align:right; white-space:nowrap">
                                    <form method="POST" action="{{ route('panel.ssl.auto', $domain) }}" class="inline-form">
                                        @csrf
                                        <button class="btn ghost sm" type="submit"><x-icon name="shield" :size="14" /> Run</button>
                                    </form>
                                    <form method="POST" action="{{ route('panel.ssl.inspect', $domain) }}" class="inline-form">
                                        @csrf
                                        <button class="btn ghost sm" type="submit" title="What is the server actually serving?"><x-icon name="eye" :size="14" /></button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h2 style="margin-top:0">Install a certificate</h2>

            <form method="POST" action="{{ route('panel.ssl.install') }}">
                @csrf
                <div class="form-row">
                    <label for="ssl-domain">Domain</label>
                    <select id="ssl-domain" name="domain_id">
                        @foreach ($domains as $domain)
                            <option value="{{ $domain->id }}">{{ $domain->domain }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row">
                    <label for="ssl-cert">Certificate (PEM)</label>
                    <textarea id="ssl-cert" name="cert_pem" rows="4" class="mono small" required>{{ old('cert_pem') }}</textarea>
                    @error('cert_pem') <span class="error-text">{{ $message }}</span> @enderror
                </div>
                <div class="form-row">
                    <label for="ssl-key">Private key (PEM)</label>
                    <textarea id="ssl-key" name="key_pem" rows="4" class="mono small">{{ old('key_pem') }}</textarea>
                    <span class="hint">Leave empty if the key was generated here by a signing request.</span>
                </div>
                <div class="form-row">
                    <label for="ssl-ca">CA bundle (optional)</label>
                    <textarea id="ssl-ca" name="ca_pem" rows="2" class="mono small">{{ old('ca_pem') }}</textarea>
                </div>
                <div class="form-actions">
                    <button class="btn primary" type="submit"><x-icon name="lock" :size="15" /> Install</button>
                </div>
            </form>

            <hr style="border:0; border-top:1px solid var(--line, #232a36); margin:18px 0">
            <h3 style="margin:0 0 8px">Need a signing request?</h3>
            <p class="small muted">Generates a key pair and a CSR on the server.</p>
            <form method="POST" action="{{ route('panel.ssl.csr', $domains->first() ?? 0) }}" class="inline-form"
                  data-confirm="Generate a new key pair and CSR?">
                @csrf
                <button class="btn ghost sm" type="submit" @disabled($domains->isEmpty())>
                    <x-icon name="file" :size="14" /> Generate CSR for {{ $domains->first()?->domain }}
                </button>
            </form>
        </div>
    </div>
@endsection
