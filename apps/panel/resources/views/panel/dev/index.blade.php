@extends('layouts.app')

@section('title', 'Git deploy')

@section('content')
    <h1>Git deployment</h1>
    <p class="small muted">
        Creates a repository inside a site's document root and pulls the tracked branch into it. Useful for a theme
        or a small application you maintain in git.
    </p>

    <div class="card" style="margin-bottom:16px">
        <h2 style="margin-top:0">Repositories</h2>

        @if ($repos->isEmpty())
            <div class="empty"><h3>No repositories</h3><p>Create one below.</p></div>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Site</th><th>Path</th><th>Branch</th><th>Remote</th><th>Last deploy</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($repos as $repo)
                            <tr>
                                <td class="small">{{ $repo->domain?->domain }}</td>
                                <td class="mono small">/{{ $repo->path }}</td>
                                <td class="small">{{ $repo->branch }}</td>
                                <td class="small faint" style="max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap">
                                    {{ $repo->remote ?: '—' }}
                                </td>
                                <td class="small faint">{{ $repo->last_deploy_at?->diffForHumans() ?? 'never' }}</td>
                                <td style="text-align:right; white-space:nowrap">
                                    <form method="POST" action="{{ route('panel.dev.deploy', $repo) }}" class="inline-form">
                                        @csrf
                                        <button class="btn ghost sm" type="submit"><x-icon name="git" :size="14" /> Deploy</button>
                                    </form>
                                    <form method="POST" action="{{ route('panel.dev.destroy', $repo) }}" class="inline-form"
                                          data-confirm="Remove this repository entry? Files are kept.">
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

    <div class="card">
        <h2 style="margin-top:0">New repository</h2>

        <form method="POST" action="{{ route('panel.dev.store') }}">
            @csrf
            <div class="inline-pair">
                <div class="form-row">
                    <label for="git-domain">Site</label>
                    <select id="git-domain" name="domain_id">
                        @foreach ($domains as $domain)
                            <option value="{{ $domain->id }}">{{ $domain->domain }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row">
                    <label for="git-path">Path inside the document root</label>
                    <input id="git-path" name="path" type="text" value="{{ old('path') }}" placeholder="public_html/theme">
                    <span class="hint">Leave empty for the document root itself.</span>
                </div>
            </div>
            <div class="inline-pair">
                <div class="form-row">
                    <label for="git-remote">Remote (optional)</label>
                    <input id="git-remote" name="remote" type="text" value="{{ old('remote') }}" placeholder="https://github.com/you/site.git">
                    <span class="hint">https://, ssh:// or git@ only.</span>
                    @error('remote') <span class="error-text">{{ $message }}</span> @enderror
                </div>
                <div class="form-row">
                    <label for="git-branch">Branch</label>
                    <input id="git-branch" name="branch" type="text" value="{{ old('branch', 'main') }}" required>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn primary" type="submit"><x-icon name="git" :size="15" /> Create repository</button>
            </div>
        </form>
    </div>
@endsection
