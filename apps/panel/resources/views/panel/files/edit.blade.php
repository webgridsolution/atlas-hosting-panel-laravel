@extends('layouts.app')

@section('title', $isNew ? 'New file' : 'Edit '.basename($path))

@php
    $content = (string) ($file['content'] ?? '');
    $lineCount = max(1, substr_count($content, "\n") + 1);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
@endphp

@section('content')
<div class="spread mb-4">
    <div style="min-width:0">
        <h1 class="mb-0 truncate" style="font-size:1.15rem">{{ basename($path) }}</h1>
        <p class="small muted mb-0 mono truncate">{{ $path }}</p>
    </div>

    <a class="btn sm ghost" href="{{ route('panel.files.index', ['path' => dirname($path)]) }}">
        <x-icon name="arrow-left" :size="14" /> Back
    </a>
</div>

@if ($isNew)
    <div class="alert mb-3">
        This file does not exist yet. It is created when you save.
    </div>
@endif

@if ($looksBinary)
    <div class="alert warn mb-3">
        This file contains bytes that are not text. Saving from this editor would replace
        them with what you see here, so it is opened read-only.
    </div>
@endif

@if (($file['truncated'] ?? false))
    <div class="alert warn mb-3">
        This file is larger than 1 MB. Only the first megabyte is shown, and saving would
        replace the whole file with that.
    </div>
@endif

<form method="POST" action="{{ route('panel.files.update') }}" id="fm-editor-form">
    @csrf
    @method('PUT')
    <input type="hidden" name="path" value="{{ $path }}">

    <div class="card flush">
        <div class="editor-bar">
            <span class="badge plain mono">{{ $ext ?: 'text' }}</span>
            <span data-editor-lines>{{ $lineCount }} {{ \Illuminate\Support\Str::plural('line', $lineCount) }}</span>
            <span class="faint hide-sm">·</span>
            <span class="hide-sm">
                <span class="kbd">Tab</span> indents · <span class="kbd">Shift</span>+<span class="kbd">Tab</span> outdents
                · <span class="kbd">Ctrl</span>+<span class="kbd">S</span> saves
            </span>
            <span class="grow"></span>
            <span class="tiny faint" data-editor-dirty aria-live="polite"></span>
        </div>

        <div class="editor">
            <div class="editor-gutter" data-editor-gutter aria-hidden="true">{{ implode("\n", range(1, $lineCount)) }}</div>
            <textarea id="content" name="content" spellcheck="false"
                      aria-label="File contents"
                      @if ($looksBinary) readonly @endif>{{ $content }}</textarea>
        </div>
    </div>

    <div class="row mt-3">
        <button class="btn primary" type="submit" @if ($looksBinary) disabled @endif>
            <x-icon name="save" :size="15" /> {{ $isNew ? 'Create file' : 'Save file' }}
        </button>

        <a class="btn ghost" href="{{ route('panel.files.index', ['path' => dirname($path)]) }}">Cancel</a>

        @unless ($looksBinary)
            <label class="checkbox" style="margin-left:auto" title="Add to the end instead of replacing">
                <input type="checkbox" name="append" value="1">
                <span>Append instead of replacing</span>
            </label>
        @endunless
    </div>
</form>
@endsection

@push('scripts')
<script src="{{ asset('assets/editor.js') }}" defer></script>
@endpush
