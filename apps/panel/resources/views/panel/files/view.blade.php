@extends('layouts.app')

@section('title', $name)

@php
    $isImage = $kind === 'image' && strtolower($ext) !== 'svg';
    $isSvg = $kind === 'svg';
    $isPdf = $kind === 'pdf';
    $isText = $kind === 'text';

    // Line numbers for the read-only text view. A file with more than 5000
    // lines is truncated rather than sent to the browser whole.
    $lines = $isText ? explode("\n", (string) $text) : [];
    $shown = array_slice($lines, 0, 5000);
@endphp

@section('content')
<div class="spread mb-4">
    <div style="min-width:0">
        <h1 class="mb-0 truncate">{{ $name }}</h1>
        <p class="small muted mb-0 mono truncate">{{ $path }}</p>
    </div>

    <div class="row">
        @if ($isText)
            <a class="btn sm" href="{{ route('panel.files.edit', ['path' => $path]) }}">
                <x-icon name="edit" :size="14" /> Edit
            </a>
        @endif

        <a class="btn sm" href="{{ route('panel.files.download', ['path' => $path]) }}">
            <x-icon name="download" :size="14" /> Download
        </a>

        <a class="btn sm ghost" href="{{ route('panel.files.index', ['path' => dirname($path)]) }}">
            <x-icon name="arrow-left" :size="14" /> Back
        </a>
    </div>
</div>

<div class="mb-3 small faint">
    @foreach ($crumbs as $i => $crumb)
        @if ($i > 0) <span class="faint">/</span> @endif
        <a href="{{ route('panel.files.index', ['path' => $crumb['path']]) }}" style="color:inherit">{{ $crumb['label'] }}</a>
    @endforeach
    <span class="faint">·</span>
    <span>{{ number_format($bytes) }} bytes</span>
</div>

@if ($truncated)
    <div class="alert warn mb-3">
        This file is larger than the 32 MB preview limit, so what follows is the beginning of it.
        Download it for the whole thing.
    </div>
@endif

<div class="card flush">
    @if ($isImage || $isSvg)
        <div style="padding:20px;display:flex;justify-content:center;background:var(--bg-soft)">
            <img src="{{ $dataUri }}" alt="{{ $name }}"
                 style="max-width:100%;max-height:70vh;border-radius:var(--radius-sm);box-shadow:var(--shadow)">
        </div>

    @elseif ($isPdf)
        <div style="padding:12px">
            <object data="{{ $dataUri }}" type="application/pdf" width="100%" height="720"
                    style="border:0;border-radius:var(--radius-sm)">
                <div class="fm-empty">
                    <div class="glyph"><x-icon name="file" :size="26" /></div>
                    <h3 class="mb-2">This browser cannot show PDFs inline</h3>
                    <p class="small muted mb-3">Download it and open it in your own viewer.</p>
                    <a class="btn sm primary" href="{{ route('panel.files.download', ['path' => $path]) }}">
                        <x-icon name="download" :size="14" /> Download
                    </a>
                </div>
            </object>
        </div>

    @elseif ($isText)
        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid var(--border);background:var(--surface-2)">
            <span class="small muted">Read-only · {{ count($shown) }} {{ \Illuminate\Support\Str::plural('line', count($shown)) }}</span>
            <span class="tiny faint mono">{{ $ext ?: 'no extension' }}</span>
        </div>

        <div style="display:flex;overflow-x:auto;max-height:75vh;font-family:var(--mono);font-size:12.5px;line-height:1.65">
            <div aria-hidden="true"
                 style="padding:14px 10px 14px 14px;text-align:right;color:var(--faint);background:var(--surface-2);user-select:none;border-right:1px solid var(--border)">
                @foreach ($shown as $i => $line)
                    {{ $i + 1 }}<br>
                @endforeach
            </div>
            <pre style="margin:0;padding:14px 16px;white-space:pre;color:var(--text);flex:1 1 auto;min-width:0">{{ implode("\n", $shown) }}</pre>
        </div>

        @if (count($lines) > count($shown))
            <div style="padding:10px 14px;border-top:1px solid var(--border)" class="small faint">
                Showing the first {{ count($shown) }} of {{ count($lines) }} lines. Download the file to see the rest.
            </div>
        @endif

    @else
        {{-- the honest answer for a binary file --}}
        <div class="fm-empty" style="padding:64px 20px">
            <div class="glyph"><x-icon name="hard-drive" :size="26" /></div>
            <h3 class="mb-2">No preview for this file</h3>
            <p class="small muted mb-4" style="max-width:42ch;margin-left:auto;margin-right:auto">
                {{ $reason }} It has no safe text representation, so the panel shows nothing rather than a page of broken characters.
            </p>
            <div class="row" style="justify-content:center">
                <a class="btn sm primary" href="{{ route('panel.files.download', ['path' => $path]) }}">
                    <x-icon name="download" :size="14" /> Download
                </a>
                <a class="btn sm" href="{{ route('panel.files.index', ['path' => dirname($path)]) }}">
                    <x-icon name="folder-open" :size="14" /> Back to the folder
                </a>
            </div>
        </div>
    @endif
</div>
@endsection
