@extends('layouts.app')

@section('title', 'File manager')

@php
    // Sort here rather than in the agent: the listing is one call, and the
    // customer may re-sort without paying for another round trip.
    $sorted = collect($entries);

    $sorted = match ($sort) {
        'size' => $sorted->sortBy(fn ($e) => (int) ($sizes[$e['path'] ?? ''] ?? $e['size'] ?? 0), SORT_REGULAR, $dir === 'desc'),
        'type' => $sorted->sortBy(fn ($e) => strtolower(pathinfo((string) ($e['name'] ?? ''), PATHINFO_EXTENSION)), SORT_REGULAR, $dir === 'desc'),
        'modified' => $sorted->sortBy(fn ($e) => (string) ($e['mod_time'] ?? ''), SORT_REGULAR, $dir === 'desc'),
        default => $sorted->sortBy(fn ($e) => strtolower((string) ($e['name'] ?? '')), SORT_REGULAR, $dir === 'desc'),
    };

    // Folders first, always: a listing that mixes them is harder to scan than
    // one that does not, whatever the sort column says.
    $sorted = $sorted->sortBy(fn ($e) => ($e['type'] ?? 'file') === 'dir' ? 0 : 1)->values();

    $iconFor = function (array $entry): array {
        if (($entry['type'] ?? 'file') === 'dir') {
            return ['folder', 'dir'];
        }

        $ext = strtolower(pathinfo((string) ($entry['name'] ?? ''), PATHINFO_EXTENSION));

        return match (true) {
            in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'bmp', 'avif'], true) => ['image', 'img'],
            in_array($ext, ['zip', 'gz', 'tgz', 'tar', 'rar', '7z'], true) => ['archive', 'arc'],
            in_array($ext, ['php', 'js', 'css', 'html', 'htm', 'json', 'xml', 'sh', 'py', 'sql'], true) => ['code', 'code'],
            default => ['file', ''],
        };
    };

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

    $sortLink = fn (string $column) => route('panel.files.index', [
        'path' => $path,
        'sort' => $column,
        'dir' => $sort === $column && $dir === 'asc' ? 'desc' : 'asc',
    ]);

    $inTrash = $path === $account->home().'/.atlas-trash';
@endphp

@section('content')
<div class="spread mb-4">
    <div>
        <h1 class="mb-0">File manager</h1>
        <p class="small muted mb-0">
            Everything here is confined to <span class="mono">{{ $account->home() }}</span>.
            Deleting moves to the trash — nothing is unlinked until you empty it.
        </p>
    </div>

    <div class="row">
        <a class="btn sm" href="{{ route('panel.files.search') }}"><x-icon name="search" :size="14" /> Search</a>
        <button class="btn sm primary" type="button" data-fm-open="upload">
            <x-icon name="upload" :size="14" /> Upload
        </button>
    </div>
</div>

<div class="fm-layout">
    {{-- ---- the places a customer actually visits ---- --}}
    <aside class="fm-rail">
        <h3>Places</h3>

        <a class="fm-place {{ $path === $account->home() ? 'active' : '' }}"
           href="{{ route('panel.files.index') }}">
            <x-icon name="home" :size="15" /> <span>Home</span>
        </a>

        @foreach ($places as $place)
            <a class="fm-place {{ $path === $place['path'] ? 'active' : '' }}"
               href="{{ route('panel.files.index', ['path' => $place['path']]) }}">
                <x-icon :name="$place['icon']" :size="15" /> <span>{{ $place['label'] }}</span>
            </a>
        @endforeach

        <h3 style="margin-top:14px">Maintenance</h3>

        <a class="fm-place {{ $inTrash ? 'active' : '' }}"
           href="{{ route('panel.files.index', ['path' => $account->home().'/.atlas-trash']) }}">
            <x-icon name="trash-2" :size="15" />
            <span>Trash</span>
            @if (count($trash) > 0)
                <span class="badge plain accent" style="margin-left:auto">{{ count($trash) }}</span>
            @endif
        </a>
    </aside>

    {{-- ---- the listing ---- --}}
    <div class="fm-main">
        <div class="fm-crumbs">
            @foreach ($crumbs as $i => $crumb)
                @if ($i > 0)
                    <span class="sep"><x-icon name="chevron-right" :size="12" /></span>
                @endif

                @if ($i === count($crumbs) - 1)
                    <span class="here">{{ $crumb['label'] }}</span>
                @else
                    <a href="{{ route('panel.files.index', ['path' => $crumb['path']]) }}">{{ $crumb['label'] }}</a>
                @endif
            @endforeach

            <span class="grow"></span>
            <span class="tiny faint nowrap">{{ count($entries) }} {{ \Illuminate\Support\Str::plural('item', count($entries)) }}</span>
        </div>

        {{-- the toolbar. Every one of these is a real action --}}
        <div class="fm-tools" role="toolbar" aria-label="File actions">
            @if ($path !== $account->home())
                <a class="btn sm ghost" href="{{ route('panel.files.index', ['path' => $parent]) }}" title="Up one level">
                    <x-icon name="arrow-left" :size="14" /> Up
                </a>
            @endif

            <button class="btn sm ghost" type="button" data-fm-open="mkdir">
                <x-icon name="folder" :size="14" /> Folder
            </button>

            <a class="btn sm ghost" href="{{ route('panel.files.edit', ['path' => $path.'/new-file.txt']) }}">
                <x-icon name="file" :size="14" /> New file
            </a>

            <span class="divider" style="width:1px;height:20px;background:var(--border);margin:0 4px"></span>

            <button class="btn sm ghost" type="button" data-fm-needs-selection data-fm-open="copy">
                <x-icon name="copy" :size="14" /> Copy
            </button>
            <button class="btn sm ghost" type="button" data-fm-needs-selection data-fm-open="move">
                <x-icon name="arrow-right" :size="14" /> Move
            </button>
            <button class="btn sm ghost" type="button" data-fm-needs-selection data-fm-open="chmod">
                <x-icon name="lock" :size="14" /> Permissions
            </button>
            <button class="btn sm ghost" type="button" data-fm-needs-selection data-fm-open="compress">
                <x-icon name="archive" :size="14" /> Compress
            </button>

            <span class="divider" style="width:1px;height:20px;background:var(--border);margin:0 4px"></span>

            {{-- Delete is a form so it is one POST for the whole selection --}}
            <form method="POST" action="{{ route('panel.files.destroy') }}" id="fm-delete" class="inline-form">
                @csrf
                @method('DELETE')
                <div id="fm-delete-inputs"></div>
                <button class="btn sm danger" type="submit" data-fm-needs-selection
                        data-confirm="Move the selected items to the trash?">
                    <x-icon name="trash-2" :size="14" /> Delete
                </button>
            </form>
        </div>

        {{-- the bar that appears once something is ticked --}}
        <div class="fm-selbar" data-fm-selbar>
            <span data-fm-count>0 selected</span>
            <span class="grow"></span>
            <button class="btn sm" type="button" data-fm-clear>Clear</button>
        </div>

        @if (empty($entries))
            <div class="fm-empty">
                <div class="glyph"><x-icon name="folder-open" :size="26" /></div>
                <h3 class="mb-2">{{ $inTrash ? 'The trash is empty' : 'This folder is empty' }}</h3>
                <p class="small muted mb-0">
                    @if ($inTrash)
                        Files you delete from the manager appear here, and can be put back.
                    @else
                        Upload a file, create a folder, or open a file to start editing.
                    @endif
                </p>
            </div>
        @else
            <div class="table-wrap">
                <table class="data" data-fm-table>
                    <thead>
                        <tr>
                            <th style="width:36px">
                                <input type="checkbox" class="checkbox" data-fm-all aria-label="Select all">
                            </th>
                            <th><a href="{{ $sortLink('name') }}" style="color:inherit;text-decoration:none">Name @if ($sort === 'name'){{ $dir === 'asc' ? '↑' : '↓' }}@endif</a></th>
                            <th class="hide-sm" style="width:100px"><a href="{{ $sortLink('size') }}" style="color:inherit;text-decoration:none">Size @if ($sort === 'size'){{ $dir === 'asc' ? '↑' : '↓' }}@endif</a></th>
                            <th class="hide-sm" style="width:90px">Mode</th>
                            <th class="hide-sm" style="width:150px"><a href="{{ $sortLink('modified') }}" style="color:inherit;text-decoration:none">Modified @if ($sort === 'modified'){{ $dir === 'asc' ? '↑' : '↓' }}@endif</a></th>
                            <th style="width:120px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sorted as $entry)
                            @php
                                $entryPath = (string) ($entry['path'] ?? $path.'/'.$entry['name']);
                                $isDir = ($entry['type'] ?? 'file') === 'dir';
                                [$icon, $iconClass] = $iconFor($entry);
                                $bytes = $isDir
                                    ? (int) ($sizes[$entryPath] ?? 0)
                                    : (int) ($entry['size'] ?? 0);
                            @endphp
                            <tr class="fm-row"
                                data-fm-row
                                data-path="{{ $entryPath }}"
                                data-name="{{ $entry['name'] }}"
                                data-type="{{ $isDir ? 'dir' : 'file' }}">
                                <td>
                                    <input type="checkbox" class="checkbox" data-fm-check
                                           value="{{ $entryPath }}" aria-label="Select {{ $entry['name'] }}">
                                </td>
                                <td>
                                    @if ($isDir)
                                        <a class="fm-name" href="{{ route('panel.files.index', ['path' => $entryPath]) }}">
                                            <span class="fm-ico {{ $iconClass }}"><x-icon :name="$icon" :size="15" /></span>
                                            <span>{{ $entry['name'] }}</span>
                                        </a>
                                    @else
                                        <a class="fm-name" href="{{ route('panel.files.view', ['path' => $entryPath]) }}">
                                            <span class="fm-ico {{ $iconClass }}"><x-icon :name="$icon" :size="15" /></span>
                                            <span>{{ $entry['name'] }}</span>
                                        </a>
                                    @endif
                                </td>
                                <td class="fm-meta hide-sm">{{ $human($bytes) }}</td>
                                <td class="fm-meta hide-sm mono">{{ $entry['mode'] ?? '—' }}</td>
                                <td class="fm-meta hide-sm">
                                    {{ empty($entry['mod_time']) ? '—' : \Illuminate\Support\Carbon::parse($entry['mod_time'])->diffForHumans() }}
                                </td>
                                <td>
                                    <div class="fm-actions">
                                        @if ($isDir)
                                            <a class="btn icon sm ghost" title="Open"
                                               href="{{ route('panel.files.index', ['path' => $entryPath]) }}">
                                                <x-icon name="folder-open" :size="13" />
                                            </a>
                                        @else
                                            <a class="btn icon sm ghost" title="View"
                                               href="{{ route('panel.files.view', ['path' => $entryPath]) }}">
                                                <x-icon name="eye" :size="13" />
                                            </a>
                                            <a class="btn icon sm ghost" title="Edit"
                                               href="{{ route('panel.files.edit', ['path' => $entryPath]) }}">
                                                <x-icon name="edit" :size="13" />
                                            </a>
                                            <a class="btn icon sm ghost" title="Download"
                                               href="{{ route('panel.files.download', ['path' => $entryPath]) }}">
                                                <x-icon name="download" :size="13" />
                                            </a>
                                        @endif

                                        <button class="btn icon sm ghost" type="button" title="More"
                                                data-fm-menu="{{ $entryPath }}" data-fm-name="{{ $entry['name'] }}"
                                                data-fm-dir="{{ $isDir ? '1' : '0' }}">
                                            <x-icon name="settings" :size="13" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- ---- the trash ---- --}}
        @if ($inTrash && count($trash) > 0)
            <div class="fm-trash">
                <div class="spread">
                    <div>
                        <h3>Restore or empty</h3>
                        <p class="small muted mb-0">Emptying the trash cannot be undone.</p>
                    </div>
                    <form method="POST" action="{{ route('panel.files.trash.empty') }}"
                          onsubmit="return confirm('Permanently delete everything in the trash? This cannot be undone.')">
                        @csrf
                        <button class="btn sm solid-danger" type="submit">
                            <x-icon name="trash-2" :size="14" /> Empty trash
                        </button>
                    </form>
                </div>

                <div class="fm-trash-list">
                    @foreach ($trash as $entry)
                        <div class="fm-trash-row">
                            <x-icon name="clock-past" :size="14" />
                            <span class="grow truncate mono">{{ $entry['name'] }}</span>
                            <span class="tiny faint nowrap">
                                {{ empty($entry['deleted_at']) ? '' : \Illuminate\Support\Carbon::parse($entry['deleted_at'])->diffForHumans() }}
                            </span>
                            <form method="POST" action="{{ route('panel.files.restore') }}" class="inline-form">
                                @csrf
                                <input type="hidden" name="path" value="{{ $entry['path'] }}">
                                <button class="btn sm" type="submit"><x-icon name="refresh" :size="13" /> Restore</button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </div>
        @elseif ($inTrash)
            <div class="fm-trash">
                <p class="small muted mb-0">Nothing has been deleted from this account.</p>
            </div>
        @endif
    </div>
</div>

{{-- ---- the drop target, only while dragging ---- --}}
<div class="fm-drop" data-fm-drop>
    <x-icon name="upload" :size="26" /> Drop to upload into {{ $path }}
</div>

{{-- ======================= dialogs ======================= --}}

<div class="dlg" id="dlg-upload" role="dialog" aria-modal="true" aria-labelledby="h-upload">
    <div class="dlg-card">
        <h3 id="h-upload">Upload files</h3>
        <p class="small muted">Up to 32 MB per file. Existing files are replaced.</p>

        <form method="POST" action="{{ route('panel.files.upload') }}" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="dir" value="{{ $path }}">

            <div class="form-row">
                <div>
                    <label for="up-files">Files</label>
                    <input id="up-files" type="file" name="files[]" multiple required>
                    <div class="field-hint">Select several at once, or drag them onto the listing.</div>
                </div>
            </div>

            <div class="form-row">
                <div class="checkbox">
                    <input id="up-ow" type="checkbox" name="overwrite" value="1" checked>
                    <label for="up-ow" style="margin:0">Replace files that already exist</label>
                </div>
            </div>

            <div class="dlg-foot">
                <button class="btn sm" type="button" data-dlg-close>Cancel</button>
                <button class="btn sm primary" type="submit"><x-icon name="upload" :size="14" /> Upload</button>
            </div>
        </form>
    </div>
</div>

<div class="dlg" id="dlg-mkdir" role="dialog" aria-modal="true" aria-labelledby="h-mkdir">
    <div class="dlg-card">
        <h3 id="h-mkdir">New folder</h3>
        <p class="small muted">Created inside <span class="mono">{{ $path }}</span>.</p>

        <form method="POST" action="{{ route('panel.files.mkdir') }}">
            @csrf
            <input type="hidden" name="path" value="{{ $path }}">
            <div class="form-row">
                <div>
                    <label for="mkdir-name">Folder name</label>
                    <input id="mkdir-name" type="text" name="name" required maxlength="255"
                           placeholder="uploads" autocomplete="off" autofocus>
                </div>
            </div>
            <div class="dlg-foot">
                <button class="btn sm" type="button" data-dlg-close>Cancel</button>
                <button class="btn sm primary" type="submit"><x-icon name="plus" :size="14" /> Create</button>
            </div>
        </form>
    </div>
</div>

<div class="dlg" id="dlg-rename" role="dialog" aria-modal="true" aria-labelledby="h-rename">
    <div class="dlg-card">
        <h3 id="h-rename">Rename</h3>
        <p class="small muted" data-fm-rename-path></p>

        <form method="POST" action="{{ route('panel.files.rename') }}">
            @csrf
            <input type="hidden" name="from" data-fm-from>
            <div class="form-row">
                <div>
                    <label for="rn-name">New name</label>
                    <input id="rn-name" type="text" name="name" required maxlength="255" autocomplete="off">
                </div>
            </div>
            <div class="dlg-foot">
                <button class="btn sm" type="button" data-dlg-close>Cancel</button>
                <button class="btn sm primary" type="submit">Rename</button>
            </div>
        </form>
    </div>
</div>

<div class="dlg" id="dlg-copy" role="dialog" aria-modal="true" aria-labelledby="h-copy">
    <div class="dlg-card">
        <h3 id="h-copy">Copy</h3>
        <p class="small muted">A folder path ending in <span class="mono">/</span> means "copy into this folder".</p>

        <form method="POST" action="{{ route('panel.files.copy') }}" data-fm-selection-form>
            @csrf
            <div class="form-row">
                <div>
                    <label for="cp-to">Destination</label>
                    <input id="cp-to" type="text" name="to" required maxlength="4096"
                           value="{{ $path }}/" class="mono" autocomplete="off">
                </div>
            </div>
            <div class="form-row">
                <div class="checkbox">
                    <input id="cp-ow" type="checkbox" name="overwrite" value="1">
                    <label for="cp-ow" style="margin:0">Replace the destination if it exists</label>
                </div>
            </div>
            <div class="dlg-foot">
                <button class="btn sm" type="button" data-dlg-close>Cancel</button>
                <button class="btn sm primary" type="submit">Copy</button>
            </div>
        </form>
    </div>
</div>

<div class="dlg" id="dlg-move" role="dialog" aria-modal="true" aria-labelledby="h-move">
    <div class="dlg-card">
        <h3 id="h-move">Move</h3>
        <p class="small muted">A rename is atomic, so a failure leaves the original where it was.</p>

        <form method="POST" action="{{ route('panel.files.move') }}" data-fm-selection-form>
            @csrf
            <div class="form-row">
                <div>
                    <label for="mv-to">Destination</label>
                    <input id="mv-to" type="text" name="to" required maxlength="4096"
                           value="{{ $path }}/" class="mono" autocomplete="off">
                </div>
            </div>
            <div class="dlg-foot">
                <button class="btn sm" type="button" data-dlg-close>Cancel</button>
                <button class="btn sm primary" type="submit">Move</button>
            </div>
        </form>
    </div>
</div>

<div class="dlg" id="dlg-chmod" role="dialog" aria-modal="true" aria-labelledby="h-chmod">
    <div class="dlg-card">
        <h3 id="h-chmod">Permissions</h3>
        <p class="small muted">
            <span class="mono">777</span> and group- and world-writable modes are refused — they are how a site gets owned.
        </p>

        <form method="POST" action="{{ route('panel.files.chmod') }}" data-fm-selection-form>
            @csrf
            <div class="form-row two">
                <div>
                    <label for="ch-mode">Mode</label>
                    <select id="ch-mode" name="mode">
                        <option value="0644">0644 — files</option>
                        <option value="0640">0640 — files, group read</option>
                        <option value="0600">0600 — private</option>
                        <option value="0755">0755 — folders and scripts</option>
                        <option value="0750">0750 — folders, group read</option>
                        <option value="0700">0700 — private folders</option>
                    </select>
                </div>
                <div>
                    <label for="ch-rec">Recursive</label>
                    <div class="checkbox" style="margin-top:9px">
                        <input id="ch-rec" type="checkbox" name="recursive" value="1">
                        <label for="ch-rec" style="margin:0">Apply to everything inside</label>
                    </div>
                </div>
            </div>
            <div class="dlg-foot">
                <button class="btn sm" type="button" data-dlg-close>Cancel</button>
                <button class="btn sm primary" type="submit">Apply</button>
            </div>
        </form>
    </div>
</div>

<div class="dlg" id="dlg-compress" role="dialog" aria-modal="true" aria-labelledby="h-compress">
    <div class="dlg-card">
        <h3 id="h-compress">Compress</h3>
        <p class="small muted">The archive is written into the folder you are in.</p>

        <form method="POST" action="{{ route('panel.files.compress') }}" data-fm-selection-form>
            @csrf
            <input type="hidden" name="dir" value="{{ $path }}">

            <div class="form-row two">
                <div>
                    <label for="cz-name">Archive name</label>
                    <input id="cz-name" type="text" name="name" maxlength="255"
                           placeholder="backup" autocomplete="off">
                    <div class="field-hint">The extension is added for you.</div>
                </div>
                <div>
                    <label for="cz-format">Format</label>
                    <select id="cz-format" name="format">
                        <option value="zip">zip</option>
                        <option value="tar.gz">tar.gz</option>
                    </select>
                </div>
            </div>
            <div class="dlg-foot">
                <button class="btn sm" type="button" data-dlg-close>Cancel</button>
                <button class="btn sm primary" type="submit"><x-icon name="archive" :size="14" /> Compress</button>
            </div>
        </form>
    </div>
</div>

<div class="dlg" id="dlg-extract" role="dialog" aria-modal="true" aria-labelledby="h-extract">
    <div class="dlg-card">
        <h3 id="h-extract">Extract archive</h3>
        <p class="small muted" data-fm-extract-name></p>

        <form method="POST" action="{{ route('panel.files.extract') }}">
            @csrf
            <input type="hidden" name="archive" data-fm-from>
            <div class="form-row">
                <div>
                    <label for="ex-dest">Extract into</label>
                    <input id="ex-dest" type="text" name="dest" required maxlength="4096"
                           value="{{ $path }}" class="mono" autocomplete="off">
                    <div class="field-hint">Members that would land outside this folder are refused.</div>
                </div>
            </div>
            <div class="dlg-foot">
                <button class="btn sm" type="button" data-dlg-close>Cancel</button>
                <button class="btn sm primary" type="submit">Extract</button>
            </div>
        </form>
    </div>
</div>

{{-- the per-row menu, positioned by the script --}}
<div class="dlg" id="dlg-menu" role="dialog" aria-modal="true" aria-labelledby="h-menu">
    <div class="dlg-card" style="max-width:360px">
        <h3 id="h-menu" class="mb-3">Actions</h3>

        <div class="stack" style="gap:4px">
            <button class="btn sm ghost" type="button" style="justify-content:flex-start" data-fm-act="rename">
                <x-icon name="edit" :size="14" /> Rename
            </button>
            <button class="btn sm ghost" type="button" style="justify-content:flex-start" data-fm-act="copy">
                <x-icon name="copy" :size="14" /> Copy
            </button>
            <button class="btn sm ghost" type="button" style="justify-content:flex-start" data-fm-act="move">
                <x-icon name="arrow-right" :size="14" /> Move
            </button>
            <button class="btn sm ghost" type="button" style="justify-content:flex-start" data-fm-act="chmod">
                <x-icon name="lock" :size="14" /> Permissions
            </button>
            <button class="btn sm ghost" type="button" style="justify-content:flex-start" data-fm-act="compress">
                <x-icon name="archive" :size="14" /> Compress
            </button>
            <button class="btn sm ghost" type="button" style="justify-content:flex-start;display:none" data-fm-act="extract">
                <x-icon name="folder-open" :size="14" /> Extract
            </button>
            <button class="btn sm danger" type="button" style="justify-content:flex-start" data-fm-act="delete">
                <x-icon name="trash-2" :size="14" /> Move to trash
            </button>
        </div>

        <div class="dlg-foot">
            <button class="btn sm" type="button" data-dlg-close>Close</button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/files.js') }}" defer></script>
@endpush
