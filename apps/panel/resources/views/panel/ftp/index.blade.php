@extends('layouts.app')

@section('title', 'FTP')

@section('content')
    <h1>FTP accounts</h1>
    <p class="small muted">Each account is a virtual user with its own home directory and optional quota. No second Linux login is created.</p>

    <div class="grid two" style="margin-bottom:16px">
        <div class="card">
            <h2 style="margin-top:0">Accounts</h2>

            @if ($accounts->isEmpty())
                <div class="empty"><h3>No FTP accounts</h3><p>Create one to upload files with an FTP client.</p></div>
            @else
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>User</th><th>Home</th><th>Quota</th><th>Last login</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($accounts as $ftp)
                                <tr>
                                    <td>
                                        <strong class="mono small">{{ $ftp->username }}</strong>
                                        @if ($ftp->read_only) <span class="badge warn">read only</span> @endif
                                    </td>
                                    <td class="mono small faint">{{ str_replace($account->home(), '~', $ftp->home_dir) }}</td>
                                    <td class="small">{{ $ftp->quotaLabel() }}</td>
                                    <td class="small faint">{{ $ftp->last_login_at?->diffForHumans() ?? 'never' }}</td>
                                    <td style="text-align:right; white-space:nowrap">
                                        <form method="POST" action="{{ route('panel.ftp.password', $ftp) }}" class="inline-form"
                                              data-confirm="Reset the password for {{ $ftp->username }}?">
                                            @csrf
                                            <button class="btn ghost sm" type="submit" title="Reset password"><x-icon name="key" :size="14" /></button>
                                        </form>
                                        <form method="POST" action="{{ route('panel.ftp.destroy', $ftp) }}" class="inline-form"
                                              data-confirm="Delete {{ $ftp->username }}? Files are not removed.">
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
            <h2 style="margin-top:0">Connection details</h2>
            <dl class="kv">
                <dt>Host</dt><dd class="mono">{{ $host }}</dd>
                <dt>Port</dt><dd class="mono">21 (explicit) · 990 (implicit TLS)</dd>
                <dt>Protocol</dt><dd>FTP with TLS (FTPS)</dd>
                <dt>Username</dt><dd>the account name shown on the left</dd>
                <dt>Password</dt><dd>generated once, shown when it is set</dd>
            </dl>
            <p class="small faint" style="margin-top:12px">
                Plain FTP is refused on this server. Configure your client for explicit TLS, or use SFTP on port 22 with the same credentials.
            </p>
        </div>
    </div>

    <div class="card">
        <h2 style="margin-top:0">New FTP account</h2>

        <form method="POST" action="{{ route('panel.ftp.store') }}">
            @csrf
            <div class="inline-pair">
                <div class="form-row">
                    <label for="ftp-name">Account name</label>
                    <input id="ftp-name" name="name" type="text" value="{{ old('name') }}" placeholder="deploy" required>
                    <span class="hint">Will be prefixed with your account name.</span>
                    @error('name') <span class="error-text">{{ $message }}</span> @enderror
                </div>
                <div class="form-row">
                    <label for="ftp-dir">Directory (optional)</label>
                    <input id="ftp-dir" name="directory" type="text" value="{{ old('directory') }}" placeholder="public_html/uploads">
                    <span class="hint">Leave empty for the document root.</span>
                </div>
            </div>
            <div class="inline-pair">
                <div class="form-row">
                    <label for="ftp-quota">Quota (MB, 0 = unlimited)</label>
                    <input id="ftp-quota" name="quota_mb" type="number" min="0" value="{{ old('quota_mb', 0) }}" required>
                </div>
                <label class="checkbox" style="align-self:end; margin-bottom:10px">
                    <input type="checkbox" name="read_only" value="1"> Read-only (upload denied)
                </label>
            </div>
            <div class="form-actions">
                <button class="btn primary" type="submit"><x-icon name="plus" :size="15" /> Create account</button>
            </div>
        </form>
    </div>
@endsection
