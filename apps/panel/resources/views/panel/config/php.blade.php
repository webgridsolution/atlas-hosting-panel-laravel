@extends('layouts.app')

@section('title', 'PHP configuration')

@section('content')
    <div class="subnav">
        <a href="{{ route('panel.config.php') }}" class="active">PHP</a>
        <a href="{{ route('panel.config.mime') }}">MIME types</a>
        <a href="{{ route('panel.config.errors') }}">Error pages</a>
    </div>

    <h1>PHP configuration</h1>
    <p class="small muted">
        The PHP version and the directives below apply to one site, not to the whole server.
        Changing the version rewrites the vhost to point at a different FPM pool.
    </p>

    @foreach ($domains as $domain)
        @php $current = $settings[$domain->id] ?? collect(); @endphp

        <div class="card" style="margin-bottom:16px">
            <div class="spread" style="margin-bottom:12px">
                <div>
                    <h2 style="margin:0">{{ $domain->domain }}</h2>
                    <p class="small faint" style="margin:2px 0 0">
                        Running PHP {{ $domain->effectivePhpVersion() }} ·
                        <a href="{{ route('panel.config.extensions', $domain) }}">loaded extensions</a>
                    </p>
                </div>
                <span class="badge {{ $domain->ssl_status === 'active' ? 'ok' : '' }}">{{ $domain->sslLabel() }}</span>
            </div>

            <form method="POST" action="{{ route('panel.config.php.store') }}">
                @csrf
                <input type="hidden" name="domain_id" value="{{ $domain->id }}">

                <div class="form-row" style="max-width:260px">
                    <label for="php-{{ $domain->id }}">PHP version</label>
                    <select id="php-{{ $domain->id }}" name="php_version">
                        @foreach ($phpVersions as $version)
                            <option value="{{ $version }}" @selected($version === $domain->effectivePhpVersion())>
                                PHP {{ $version }}{{ $version === $defaultVersion ? ' (default)' : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="grid two" style="margin-top:12px">
                    @foreach ($directives as $directive)
                        @php $value = $current->firstWhere('key', $directive)?->value; @endphp
                        <div class="form-row">
                            <label for="d-{{ $domain->id }}-{{ $directive }}" class="mono small">{{ $directive }}</label>
                            <input id="d-{{ $domain->id }}-{{ $directive }}"
                                   name="settings[{{ $directive }}]" type="text"
                                   value="{{ old('settings.'.$directive, $value) }}"
                                   placeholder="inherit">
                        </div>
                    @endforeach
                </div>

                <div class="form-actions">
                    <button class="btn primary" type="submit"><x-icon name="check" :size="15" /> Save for {{ $domain->domain }}</button>
                </div>
            </form>
        </div>
    @endforeach

    @if ($domains->isEmpty())
        <div class="card"><div class="empty"><h3>No sites yet</h3><p>Add a domain to configure PHP for it.</p></div></div>
    @endif
@endsection
