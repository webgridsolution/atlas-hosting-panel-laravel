@extends('layouts.app')

@section('title', 'MIME types')

@section('content')
    <div class="subnav">
        <a href="{{ route('panel.config.php') }}">PHP</a>
        <a href="{{ route('panel.config.mime') }}" class="active">MIME types</a>
        <a href="{{ route('panel.config.errors') }}">Error pages</a>
    </div>

    <h1>MIME types</h1>
    <p class="small muted">Tells the web server what a file extension means. Overrides are written to your account's .htaccess.</p>

    <div class="grid two">
        <div class="card">
            <h2 style="margin-top:0">Overrides</h2>

            @if ($mimeTypes->isEmpty())
                <div class="empty"><h3>No overrides</h3><p>The server's built-in map is in use.</p></div>
            @else
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Extension</th><th>Served as</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($mimeTypes as $mime)
                                <tr>
                                    <td class="mono small">.{{ $mime->extension }}</td>
                                    <td class="mono small">{{ $mime->mime_type }}</td>
                                    <td style="text-align:right">
                                        <form method="POST" action="{{ route('panel.config.mime.destroy', $mime) }}" class="inline-form"
                                              data-confirm="Remove the override for .{{ $mime->extension }}?">
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
            <h2 style="margin-top:0">Add an override</h2>

            <form method="POST" action="{{ route('panel.config.mime.store') }}">
                @csrf
                <div class="form-row">
                    <label for="mime-ext">Extension</label>
                    <input id="mime-ext" name="extension" type="text" value="{{ old('extension') }}" placeholder="webp" required>
                    @error('extension') <span class="error-text">{{ $message }}</span> @enderror
                </div>
                <div class="form-row">
                    <label for="mime-type">MIME type</label>
                    <input id="mime-type" name="mime_type" type="text" value="{{ old('mime_type') }}" placeholder="image/webp" required>
                    @error('mime_type') <span class="error-text">{{ $message }}</span> @enderror
                </div>
                <div class="form-actions">
                    <button class="btn primary" type="submit"><x-icon name="plus" :size="15" /> Add</button>
                </div>
            </form>

            <h3 style="margin-top:20px">Common ones</h3>
            <p class="small faint" style="margin:0">
                webp → image/webp · avif → image/avif · woff2 → font/woff2 ·
                mjs → text/javascript · wasm → application/wasm
            </p>
        </div>
    </div>
@endsection
