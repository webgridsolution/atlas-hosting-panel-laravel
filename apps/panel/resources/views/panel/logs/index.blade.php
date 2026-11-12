@extends('layouts.app')

@section('title', 'Logs')

@php
    $human = function (int $bytes): string {
        if ($bytes <= 0) {
            return '0 B';
        }
        foreach (['B', 'KB', 'MB', 'GB'] as $i => $unit) {
            if ($bytes < 1024 ** ($i + 1) || $unit === 'GB') {
                return round($bytes / (1024 ** $i), $i === 0 ? 0 : 1).' '.$unit;
            }
        }

        return $bytes.' B';
    };

    // Access-log lines get their fields coloured by position so a 404 stands out
    // without parsing the format. Anything that does not match stays plain text.
    $classify = function (string $line): string {
        if (preg_match('/"\s(4\d\d|5\d\d)\s/', $line) === 1) {
            return 'err';
        }
        if (preg_match('/"\s3\d\d\s/', $line) === 1) {
            return 'warn';
        }

        return '';
    };
@endphp

@section('content')
<div class="spread mb-4">
    <div>
        <h1 class="mb-0">Logs</h1>
        <p class="small muted mb-0">
            The last {{ \App\Http\Controllers\Panel\LogController::TAIL_DISPLAY }} lines of
            <span class="mono">{{ $dir }}</span>. Only this folder is readable from here.
        </p>
    </div>

    <a class="btn sm ghost" href="{{ route('panel.files.index', ['path' => $dir]) }}">
        <x-icon name="folder" :size="14" /> Open in file manager
    </a>
</div>

@if ($error)
    <div class="alert mb-3">{{ $error }}</div>
@endif

@if ($truncated)
    <div class="alert warn mb-3">
        This log is larger than 2 MB. You are looking at the end of it.
    </div>
@endif

@if (empty($files))
    <div class="card">
        <div class="fm-empty" style="padding:56px 20px">
            <div class="glyph"><x-icon name="receipt" :size="26" /></div>
            <h3 class="mb-2">No logs yet</h3>
            <p class="small muted mb-0" style="max-width:44ch;margin:0 auto">
                This account has not written a log file. Error and access logs appear here
                once the site has served a request.
            </p>
        </div>
    </div>
@else
    <div class="grid sidebar-right">
        <div class="card flush" style="min-width:0">
            <div class="editor-bar">
                <span class="mono">{{ $selected ?: 'nothing selected' }}</span>
                <span class="grow"></span>
                <span class="tiny faint">{{ count($lines) }} {{ \Illuminate\Support\Str::plural('line', count($lines)) }}</span>

                @if ($selected !== '')
                    <a class="btn sm ghost" href="{{ route('panel.files.download', ['path' => $dir.'/'.$selected]) }}">
                        <x-icon name="download" :size="13" /> Download
                    </a>
                @endif
            </div>

            @if (empty($lines))
                <div class="fm-empty" style="padding:44px 20px">
                    <p class="small muted mb-0">This log is empty.</p>
                </div>
            @else
                <div class="log-view" role="log" aria-label="Log contents">
                    @foreach ($lines as $i => $line)
                        <div class="log-line {{ $classify($line) }}">
                            <span class="n">{{ $i + 1 }}</span>
                            <span class="t">{!! nl2br(e($line)) !!}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <aside>
            <div class="card">
                <div class="card-head mb-3">
                    <div>
                        <h2>Files</h2>
                        <p>Newest writes first.</p>
                    </div>
                </div>

                <div class="stack" style="gap:4px">
                    @foreach ($files as $file)
                        <a class="fm-place {{ $file['name'] === $selected ? 'active' : '' }}"
                           href="{{ route('panel.logs.index', ['file' => $file['name']]) }}">
                            <x-icon name="file" :size="14" />
                            <span class="grow truncate">{{ $file['name'] }}</span>
                            <span class="tiny faint nowrap">{{ $human($file['size']) }}</span>
                        </a>
                    @endforeach
                </div>
            </div>

            <div class="card mt-3">
                <h2 style="font-size:.95rem;margin:0 0 6px">Reading a log</h2>
                <p class="small muted mb-0">
                    A red line is a request that ended in a 4xx or 5xx. Amber is a redirect.
                    Log lines contain whatever a visitor sent, so they are shown as text —
                    never as HTML.
                </p>
            </div>
        </aside>
    </div>
@endif
@endsection
