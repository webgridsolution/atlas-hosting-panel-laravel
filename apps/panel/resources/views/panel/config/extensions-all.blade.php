@extends('layouts.app')

@section('title', 'PHP extensions')

@section('content')
<div class="spread mb-4">
    <div>
        <h1 class="mb-0">PHP extensions</h1>
        <p class="small muted mb-0">
            What each PHP version on this account has loaded. Extensions are compiled into the FPM
            build, so this is a report, not a switch — ask your provider to add one.
        </p>
    </div>

    <a class="btn sm ghost" href="{{ route('panel.config.php') }}">
        <x-icon name="php" :size="14" /> PHP configuration
    </a>
</div>

@if ($error)
    <div class="alert mb-4">{{ $error }}</div>
@endif

@if (empty($byVersion))
    <div class="card">
        <div class="fm-empty" style="padding:52px 20px">
            <div class="glyph"><x-icon name="puzzle" :size="26" /></div>
            <h3 class="mb-2">No PHP version in use yet</h3>
            <p class="small muted mb-0">Add a domain and it will get a PHP version, then its extensions appear here.</p>
        </div>
    </div>
@else
    @foreach ($byVersion as $version => $extensions)
        <div class="card mb-4">
            <div class="card-head mb-3">
                <div>
                    <h2>PHP {{ $version }}</h2>
                    <p>
                        {{ count($extensions) }} {{ \Illuminate\Support\Str::plural('extension', count($extensions)) }} ·
                        used by
                        {{ $domains->filter(fn ($d) => $d->effectivePhpVersion() === (string) $version)->pluck('domain')->take(4)->implode(', ') ?: 'no domain' }}
                    </p>
                </div>

                <span class="badge {{ count($extensions) > 0 ? 'ok' : 'danger' }}">
                    {{ count($extensions) > 0 ? 'readable' : 'unavailable' }}
                </span>
            </div>

            @if (empty($extensions))
                <p class="small muted mb-0">
                    The extension list could not be read from this PHP build. That usually means the
                    binary is missing or the agent has no permission to run it.
                </p>
            @else
                <div class="ext-grid">
                    @foreach ($extensions as $extension)
                        <div class="ext {{ $extension['enabled'] ? 'on' : 'off' }}">
                            <span class="dot"></span>
                            <span class="mono">{{ $extension['name'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endforeach
@endif
@endsection
