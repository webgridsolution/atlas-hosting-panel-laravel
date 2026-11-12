@extends('layouts.app')

@section('title', 'Domains')

@section('content')
    <div class="spread" style="margin-bottom:16px">
        <div>
            <h1 style="margin:0">Domains</h1>
            <p class="small muted" style="margin:4px 0 0">
                Addon domains get their own document root and vhost. Subdomains live under a name you already host. Aliases serve an existing site under a second name.
            </p>
        </div>
        <span class="badge {{ $atLimit ? 'warn' : '' }}">
            {{ $domains->count() }} / {{ $limit ?? '∞' }}
        </span>
    </div>

    <div class="card" style="margin-bottom:16px">
        <h2 style="margin-top:0">Your domains</h2>

        @if ($domains->isEmpty())
            <div class="empty"><h3>No domains yet</h3><p>Add one below.</p></div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>Domain</th><th>Type</th><th>Document root</th><th>PHP</th><th>SSL</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($domains as $domain)
                            <tr>
                                <td>
                                    <strong>{{ $domain->domain }}</strong>
                                    @if ($domain->is_primary) <span class="badge accent">primary</span> @endif
                                </td>
                                <td><span class="badge">{{ $domain->typeLabel() }}</span></td>
                                <td class="mono small faint">
                                    {{ str_replace($account->home(), '~', $domain->document_root) }}
                                </td>
                                <td class="small">{{ $domain->effectivePhpVersion() }}</td>
                                <td>
                                    <span class="badge {{ $domain->ssl_status === 'active' ? 'ok' : ($domain->ssl_status === 'failed' ? 'danger' : 'warn') }}">
                                        {{ $domain->sslLabel() }}
                                    </span>
                                </td>
                                <td style="text-align:right">
                                    @unless ($domain->is_primary)
                                        <form method="POST" action="{{ route('panel.domains.destroy', $domain) }}" class="inline-form"
                                              data-confirm="Remove {{ $domain->domain }}? Its files stay on disk.">
                                            @csrf @method('DELETE')
                                            <button class="btn ghost sm" type="submit"><x-icon name="trash" :size="14" /></button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="grid two">
        {{-- addon --}}
        <div class="card">
            <h2 style="margin-top:0">Add an addon domain</h2>
            <p class="small muted">A separate site with its own root, vhost, and optional mailbox domain.</p>

            <form method="POST" action="{{ route('panel.domains.addon') }}">
                @csrf
                <div class="form-row">
                    <label for="addon-domain">Domain</label>
                    <input id="addon-domain" name="domain" type="text" value="{{ old('domain') }}"
                           placeholder="shop.example.com" required>
                    @error('domain') <span class="error-text">{{ $message }}</span> @enderror
                </div>
                <div class="form-row cols">
                    <div>
                        <label for="addon-php">PHP version</label>
                        <select id="addon-php" name="php_version">
                            @foreach ($phpVersions as $v)
                                <option value="{{ $v }}" @selected($v === $account->php_version)>{{ $v }}</option>
                            @endforeach
                        </select>
                    </div>
                    <label class="checkbox" style="align-self:end; margin-bottom:10px">
                        <input type="checkbox" name="with_mail" value="1" checked> Create mail domain
                    </label>
                </div>
                <div class="form-actions">
                    <button class="btn primary" type="submit"><x-icon name="plus" :size="15" /> Add domain</button>
                </div>
            </form>
        </div>

        {{-- subdomain --}}
        <div class="card">
            <h2 style="margin-top:0">Add a subdomain</h2>
            <p class="small muted">Either a folder under an existing site, or a site of its own.</p>

            <form method="POST" action="{{ route('panel.domains.subdomain') }}">
                @csrf
                <div class="form-row">
                    <label for="sub-parent">Under</label>
                    <select id="sub-parent" name="parent_id">
                        @foreach ($sites as $site)
                            <option value="{{ $site->id }}">{{ $site->domain }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row">
                    <label for="sub-label">Label</label>
                    <input id="sub-label" name="label" type="text" value="{{ old('label') }}"
                           placeholder="shop" required pattern="[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?">
                    <span class="hint">Becomes <span class="mono">shop.{{ $sites->first()?->domain ?? 'example.com' }}</span></span>
                    @error('label') <span class="error-text">{{ $message }}</span> @enderror
                </div>
                <label class="checkbox"><input type="checkbox" name="own_root" value="1" checked> Give it its own document root</label>
                <div class="form-actions">
                    <button class="btn primary" type="submit"><x-icon name="plus" :size="15" /> Add subdomain</button>
                </div>
            </form>
        </div>
    </div>

    {{-- parked --}}
    <div class="card" style="margin-top:16px">
        <h2 style="margin-top:0">Park a domain on an existing site</h2>
        <p class="small muted">The alias serves exactly the same files as the site you choose. This is what "parked domain" means here — nothing is copied.</p>

        <form method="POST" action="{{ route('panel.domains.parked') }}">
            @csrf
            <div class="inline-pair">
                <div class="form-row">
                    <label for="park-target">Serve the site at</label>
                    <select id="park-target" name="target_id">
                        @foreach ($sites as $site)
                            <option value="{{ $site->id }}">{{ $site->domain }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row">
                    <label for="park-domain">Alias domain</label>
                    <input id="park-domain" name="domain" type="text" value="{{ old('domain') }}" placeholder="alias.example.net" required>
                    @error('domain') <span class="error-text">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="form-actions">
                <button class="btn primary" type="submit"><x-icon name="link" :size="15" /> Park domain</button>
            </div>
        </form>
    </div>
@endsection
