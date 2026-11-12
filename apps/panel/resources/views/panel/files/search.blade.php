@extends('layouts.app')

@section('title', 'Search files')

@php
    $human = function (int $bytes): string {
        if ($bytes <= 0) {
            return '—';
        }
        foreach (['B', 'KB', 'MB', 'GB'] as $i => $unit) {
            if ($bytes < 1024 ** ($i + 1) || $unit === 'GB') {
                return round($bytes / (1024 ** $i), $i === 0 ? 0 : 1).' '.$unit;
            }
        }

        return $bytes.' B';
    };
@endphp

@section('content')
<div class="spread mb-4">
    <div>
        <h1 class="mb-0">Search</h1>
        <p class="small muted mb-0">Names, not patterns. Searching for <span class="mono">config</span> finds <span class="mono">wp-config.php</span>.</p>
    </div>

    <a class="btn sm ghost" href="{{ route('panel.files.index', ['path' => $path]) }}">
        <x-icon name="arrow-left" :size="14" /> Back to the folder
    </a>
</div>

<div class="card mb-4">
    <form method="GET" action="{{ route('panel.files.search') }}" class="stack">
        <div class="form-row two" style="margin-bottom:0">
            <div>
                <label for="q">What to look for</label>
                <input id="q" type="search" name="q" value="{{ $query }}" maxlength="200"
                       placeholder="wp-config" autocomplete="off" autofocus>
            </div>
            <div>
                <label for="in">Where</label>
                <select id="in" name="in">
                    <option value="home" @selected($in === 'home')>The whole account</option>
                    <option value="public_html" @selected($in === 'public_html')>public_html only</option>
                    <option value="current" @selected($in === 'current')>This folder — {{ $scope }}</option>
                </select>
            </div>
        </div>

        <input type="hidden" name="path" value="{{ $path }}">

        <div class="row">
            <button class="btn sm primary" type="submit"><x-icon name="search" :size="14" /> Search</button>
            @if ($query !== '')
                <a class="btn sm ghost" href="{{ route('panel.files.search') }}">Clear</a>
            @endif
        </div>
    </form>
</div>

@if ($invalid)
    <div class="alert mb-4">{{ $invalid }}</div>
@endif

@if ($query !== '' && ! $invalid)
    <div class="spread mb-3">
        <p class="small muted mb-0">
            {{ count($hits) }} {{ \Illuminate\Support\Str::plural('match', count($hits)) }} for
            <strong>{{ $query }}</strong> in <span class="mono">{{ $scope }}</span>
        </p>
    </div>

    @if (empty($hits))
        <div class="card">
            <div class="fm-empty" style="padding:52px 20px">
                <div class="glyph"><x-icon name="search" :size="26" /></div>
                <h3 class="mb-2">Nothing matched</h3>
                <p class="small muted mb-0">
                    Try a shorter term, or widen the scope to the whole account.
                </p>
            </div>
        </div>
    @else
        <div class="card flush">
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th class="hide-sm" style="width:110px">Size</th>
                            <th class="hide-sm" style="width:150px">Modified</th>
                            <th style="width:110px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($hits as $hit)
                            @php
                                $hitPath = (string) ($hit['path'] ?? '');
                                $isDir = ($hit['type'] ?? 'file') === 'dir';
                                $hitName = basename($hitPath);
                            @endphp
                            <tr>
                                <td>
                                    @if ($isDir)
                                        <a class="fm-name" href="{{ route('panel.files.index', ['path' => $hitPath]) }}">
                                            <span class="fm-ico dir"><x-icon name="folder" :size="15" /></span>
                                            <span>{{ $hitName }}</span>
                                        </a>
                                    @else
                                        <a class="fm-name" href="{{ route('panel.files.view', ['path' => $hitPath]) }}">
                                            <span class="fm-ico"><x-icon name="file" :size="15" /></span>
                                            <span>{{ $hitName }}</span>
                                        </a>
                                    @endif
                                    <div class="tiny faint mono truncate" style="margin-left:35px">{{ dirname($hitPath) }}</div>
                                </td>
                                <td class="fm-meta hide-sm">{{ $isDir ? '—' : $human((int) ($hit['size'] ?? 0)) }}</td>
                                <td class="fm-meta hide-sm">
                                    {{ empty($hit['mod_time']) ? '—' : \Illuminate\Support\Carbon::parse($hit['mod_time'])->diffForHumans() }}
                                </td>
                                <td>
                                    <div class="fm-actions">
                                        @unless ($isDir)
                                            <a class="btn icon sm ghost" title="Edit"
                                               href="{{ route('panel.files.edit', ['path' => $hitPath]) }}">
                                                <x-icon name="edit" :size="13" />
                                            </a>
                                            <a class="btn icon sm ghost" title="Download"
                                               href="{{ route('panel.files.download', ['path' => $hitPath]) }}">
                                                <x-icon name="download" :size="13" />
                                            </a>
                                        @endunless
                                        <a class="btn icon sm ghost" title="Show in folder"
                                           href="{{ route('panel.files.index', ['path' => dirname($hitPath)]) }}">
                                            <x-icon name="folder-open" :size="13" />
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endif
@endsection
