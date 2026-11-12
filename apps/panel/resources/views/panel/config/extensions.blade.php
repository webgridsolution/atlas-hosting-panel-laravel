@extends('layouts.app')

@section('title', 'PHP extensions')

@section('content')
    <div class="subnav">
        <a href="{{ route('panel.config.php') }}">PHP</a>
        <a href="{{ route('panel.config.mime') }}">MIME types</a>
        <a href="{{ route('panel.config.errors') }}">Error pages</a>
    </div>

    <h1>PHP extensions — {{ $domain->domain }}</h1>
    <p class="small muted">Read directly from the PHP {{ $domain->effectivePhpVersion() }} binary that serves this site.</p>

    @if ($error)
        <div class="alert error">
            <x-icon name="alert" :size="16" />
            <div>{{ $error }}</div>
        </div>
    @else
        <div class="card">
            <div class="spread" style="margin-bottom:10px">
                <strong>{{ count($extensions) }} extension(s) loaded</strong>
                <a class="btn ghost sm" href="{{ route('panel.config.php') }}">Back to PHP settings</a>
            </div>

            <div class="row" style="gap:6px; flex-wrap:wrap">
                @foreach ($extensions as $extension)
                    <span class="badge {{ ($extension['enabled'] ?? true) ? 'ok' : '' }}">
                        {{ $extension['name'] }}
                    </span>
                @endforeach
            </div>
        </div>
    @endif
@endsection
