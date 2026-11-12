<x-app-layout title="Releases" :crumbs="[['label' => 'Releases']]">
    <div class="page-head">
        <div>
            <h1>Releases</h1>
            <p>What installed panels pull. Artifacts are checksummed and signed; a version is invisible until published.</p>
        </div>
    </div>

    @can('create', App\Models\Release::class)
        <div class="card mb-3">
            <div class="card-head"><h2>Register a release</h2></div>
            <form method="POST" action="{{ route('admin.releases.store') }}" class="card-body">
                @csrf
                <div class="grid cols-3" style="gap:12px">
                    <x-select label="Component" name="component" required>
                        <option value="panel">Panel</option>
                        <option value="agent">Agent</option>
                        <option value="installer">Installer</option>
                    </x-select>
                    <x-input label="Version" name="version" placeholder="1.4.0" required hint="Semantic x.y.z" />
                    <x-select label="Channel" name="channel" required>
                        <option value="stable">Stable</option>
                        <option value="beta">Beta</option>
                    </x-select>
                </div>

                <x-textarea label="Release notes" name="notes" rows="3" placeholder="What changed, migration notes, breaking changes." />

                <div class="panel-flat">
                    <div class="eyebrow mb-2">Artifact</div>
                    <div class="grid cols-2" style="gap:12px">
                        <x-input label="OS" name="artifacts[0][os]" value="ubuntu-24.04" required />
                        <x-input label="Architecture" name="artifacts[0][arch]" value="amd64" required />
                    </div>
                    <x-input label="Filename" name="artifacts[0][filename]" placeholder="atlas-panel-1.4.0-linux-amd64.tar.gz" required />
                    <div class="grid cols-2" style="gap:12px">
                        <x-input label="Size (bytes)" name="artifacts[0][size_bytes]" type="number" required />
                        <x-input label="SHA-256" name="artifacts[0][sha256]" required hint="64 hex characters" />
                    </div>
                    <x-input label="URL" name="artifacts[0][url]" type="url" placeholder="https://cdn.example.com/atlas/…" required />
                    <x-textarea label="Detached signature (base64)" name="artifacts[0][signature]" rows="2" required />
                </div>

                <label class="checkbox-row">
                    <input type="checkbox" name="is_security_release" value="1">
                    <span>Security release (panels are notified immediately)</span>
                </label>
                <label class="checkbox-row">
                    <input type="checkbox" name="publish" value="1" checked>
                    <span>Publish now (otherwise saved as a draft)</span>
                </label>

                <div class="form-actions">
                    <button class="btn btn-primary">Save release</button>
                </div>
            </form>
        </div>
    @endcan

    @if ($releases->isEmpty())
        <x-empty icon="layers" title="No releases registered" description="Register the first build so panels can update themselves." />
    @else
        <div class="card">
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Component</th><th>Version</th><th>Channel</th><th>Artifacts</th><th>State</th><th>Published</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($releases as $release)
                            <tr>
                                <td class="cell-strong">{{ ucfirst($release->component) }}</td>
                                <td class="mono">{{ $release->version }}</td>
                                <td><x-badge variant="neutral">{{ $release->channel }}</x-badge></td>
                                <td class="num">{{ $release->artifacts->count() }}</td>
                                <td>
                                    <x-badge :variant="$release->is_published ? 'ok' : 'warn'">{{ $release->is_published ? 'Published' : 'Draft' }}</x-badge>
                                    @if ($release->is_security_release)<span class="badge badge-danger">Security</span>@endif
                                </td>
                                <td class="num small">{{ $release->published_at?->format('d M Y') ?? '—' }}</td>
                                <td class="text-right">
                                    @if (! $release->is_published)
                                        @can('update', $release)
                                            <form method="POST" action="{{ route('admin.releases.publish', $release) }}">
                                                @csrf @method('PATCH')
                                                <button class="btn btn-secondary btn-sm">Publish</button>
                                            </form>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $releases->links() }}
        </div>
    @endif
</x-app-layout>
