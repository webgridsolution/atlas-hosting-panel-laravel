<x-app-layout title="Downloads" :crumbs="[['label' => 'Downloads']]">
    <div class="page-head">
        <div>
            <h1>Downloads</h1>
            <p>Signed, checksummed builds. Verify the SHA-256 and signature before running anything as root.</p>
        </div>
    </div>

    @unless ($hasLicence)
        <x-alert variant="warn" class="mb-3" title="An active licence is required">
            Builds are gated on an active licence so that downloads can be traced. Buy a licence and this page unlocks.
        </x-alert>
    @endunless

    @if ($releases->isEmpty())
        <x-empty icon="download" title="No builds published yet" description="Releases appear here once the release pipeline publishes a signed artifact." />
    @else
        @foreach ($releases as $release)
            <div class="card mb-3">
                <div class="card-head">
                    <div class="row" style="gap:10px;align-items:baseline">
                        <h2>{{ ucfirst($release->component) }} <span class="mono">v{{ $release->version }}</span></h2>
                        <span class="badge badge-neutral">{{ $release->channel }}</span>
                        @if ($release->is_security_release)
                            <span class="badge badge-danger">Security release</span>
                        @endif
                    </div>
                    <span class="small muted">{{ $release->published_at?->format('d M Y') }}</span>
                </div>

                @if ($release->notes)
                    <div class="card-body" style="padding-bottom:0">
                        <div class="prose small">{!! nl2br(e($release->notes)) !!}</div>
                    </div>
                @endif

                <div class="table-wrap" style="margin-top:12px">
                    <table class="table">
                        <thead><tr><th>Platform</th><th>File</th><th>Size</th><th>SHA-256</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($release->artifacts as $artifact)
                                <tr>
                                    <td class="cell-strong">{{ $artifact->os }} / {{ $artifact->arch }}</td>
                                    <td class="mono small">{{ $artifact->filename }}</td>
                                    <td class="num">{{ number_format($artifact->size_bytes / 1048576, 1) }} MB</td>
                                    <td>
                                        <button type="button" class="copy-btn" data-copy="{{ $artifact->sha256 }}" title="Copy checksum">
                                            <span class="mono small">{{ substr($artifact->sha256, 0, 16) }}…</span>
                                            <x-icon name="copy" :size="13" />
                                        </button>
                                    </td>
                                    <td class="text-right">
                                        @if ($hasLicence)
                                            <a href="{{ route('downloads.get', $artifact) }}" class="btn btn-secondary btn-sm">
                                                <x-icon name="download" :size="14" /> Download
                                            </a>
                                        @else
                                            <span class="small muted">Licence required</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach

        <x-alert variant="info" title="Verify before installing">
            <code class="mono">sha256sum atlas-installer-linux-amd64 &amp;&amp; atlas-install verify --sig atlas-installer-linux-amd64.sig</code>
        </x-alert>
    @endif
</x-app-layout>
