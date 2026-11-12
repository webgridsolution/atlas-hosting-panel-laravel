@extends('layouts.app')

@section('title', 'Packages')

@section('content')
    <h1>Hosting packages</h1>
    <p class="small muted">A package is the set of limits an account gets. Prices shown here are labels only — billing lives in the central system.</p>

    <div class="card">
        <div class="card-head"><h2>Create a package</h2></div>

        <form method="POST" action="{{ route('whm.packages.store') }}">
            @csrf

            <div class="form-row cols">
                <div>
                    <label for="name">Name</label>
                    <input id="name" name="name" type="text" value="{{ old('name') }}" placeholder="Business" required>
                </div>
                <div>
                    <label for="php_version">PHP version</label>
                    <select id="php_version" name="php_version">
                        @foreach (['8.1', '8.2', '8.3', '8.4'] as $version)
                            <option value="{{ $version }}" @selected(old('php_version', '8.3') === $version)>PHP {{ $version }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="form-row cols">
                <div>
                    <label for="disk_mb">Disk (MB)</label>
                    <input id="disk_mb" name="disk_mb" type="number" min="256" value="{{ old('disk_mb', 10240) }}" required>
                </div>
                <div>
                    <label for="inodes">Inodes</label>
                    <input id="inodes" name="inodes" type="number" min="1000" value="{{ old('inodes', 200000) }}" required>
                </div>
            </div>

            <div class="form-row cols">
                <div>
                    <label for="bandwidth_mb">Bandwidth (MB / month)</label>
                    <input id="bandwidth_mb" name="bandwidth_mb" type="number" min="1024" value="{{ old('bandwidth_mb', 100000) }}" required>
                </div>
                <div>
                    <label for="price_paise">Label price (paise)</label>
                    <input id="price_paise" name="price_paise" type="number" min="0" value="{{ old('price_paise', 0) }}">
                </div>
            </div>

            <div class="form-row cols">
                <div>
                    <label for="max_domains">Max websites</label>
                    <input id="max_domains" name="max_domains" type="number" min="1" value="{{ old('max_domains', 1) }}" required>
                </div>
                <div>
                    <label for="max_databases">Max databases</label>
                    <input id="max_databases" name="max_databases" type="number" min="0" value="{{ old('max_databases', 1) }}" required>
                </div>
            </div>

            <div class="form-row cols">
                <div>
                    <label for="max_mailboxes">Max mailboxes</label>
                    <input id="max_mailboxes" name="max_mailboxes" type="number" min="0" value="{{ old('max_mailboxes', 1) }}" required>
                </div>
                <div>
                    <label for="description">Description</label>
                    <input id="description" name="description" type="text" value="{{ old('description') }}">
                </div>
            </div>

            <label class="checkbox" style="margin-bottom:14px">
                <input type="checkbox" name="is_active" value="1" checked> Available for new accounts
            </label>

            <button class="btn primary" type="submit">Create package</button>
        </form>
    </div>

    <div class="card">
        <h2>Existing packages</h2>

        @if ($packages->isEmpty())
            <div class="empty"><h3>No packages yet</h3><p>Create one above to start provisioning.</p></div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>Name</th><th>Disk</th><th>Inodes</th><th>Sites</th><th>Mail</th><th>PHP</th><th>In use</th><th class="right">Actions</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($packages as $package)
                            <tr>
                                <td>
                                    <strong>{{ $package->name }}</strong>
                                    @unless ($package->is_active) <span class="badge warn">inactive</span> @endunless
                                    <div class="faint small">{{ $package->description }}</div>
                                </td>
                                <td class="small">{{ $package->diskLabel() }}</td>
                                <td class="small">{{ number_format($package->inodes) }}</td>
                                <td class="small">{{ $package->max_domains }}</td>
                                <td class="small">{{ $package->max_mailboxes }}</td>
                                <td class="small">{{ $package->php_version }}</td>
                                <td class="small">{{ $package->accounts_count }}</td>
                                <td class="right">
                                    <form method="POST" action="{{ route('whm.packages.destroy', $package) }}" class="inline-form"
                                          onsubmit="return confirm('Delete this package?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn sm danger" type="submit">Delete</button>
                                    </form>
                                </td>
                            </tr>

                            <tr>
                                <td colspan="8" style="padding-top:0">
                                    <details>
                                        <summary class="small faint" style="cursor:pointer">Edit {{ $package->name }}</summary>
                                        <form method="POST" action="{{ route('whm.packages.update', $package) }}" style="margin-top:12px">
                                            @csrf
                                            @method('PATCH')

                                            <div class="form-row cols">
                                                <div><label>Name</label><input name="name" type="text" value="{{ $package->name }}" required></div>
                                                <div><label>Description</label><input name="description" type="text" value="{{ $package->description }}"></div>
                                            </div>
                                            <div class="form-row cols">
                                                <div><label>Disk (MB)</label><input name="disk_mb" type="number" value="{{ $package->disk_mb }}" required></div>
                                                <div><label>Inodes</label><input name="inodes" type="number" value="{{ $package->inodes }}" required></div>
                                            </div>
                                            <div class="form-row cols">
                                                <div><label>Bandwidth (MB)</label><input name="bandwidth_mb" type="number" value="{{ $package->bandwidth_mb }}" required></div>
                                                <div><label>Price (paise)</label><input name="price_paise" type="number" value="{{ $package->price_paise }}"></div>
                                            </div>
                                            <div class="form-row cols">
                                                <div><label>Max websites</label><input name="max_domains" type="number" value="{{ $package->max_domains }}" required></div>
                                                <div><label>Max databases</label><input name="max_databases" type="number" value="{{ $package->max_databases }}" required></div>
                                            </div>
                                            <div class="form-row cols">
                                                <div><label>Max mailboxes</label><input name="max_mailboxes" type="number" value="{{ $package->max_mailboxes }}" required></div>
                                                <div>
                                                    <label>PHP</label>
                                                    <select name="php_version">
                                                        @foreach (['8.1', '8.2', '8.3', '8.4'] as $version)
                                                            <option value="{{ $version }}" @selected($package->php_version === $version)>PHP {{ $version }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                            </div>

                                            <label class="checkbox" style="margin-bottom:12px">
                                                <input type="checkbox" name="is_active" value="1" @checked($package->is_active)> Available for new accounts
                                            </label>

                                            <button class="btn" type="submit">Save changes</button>
                                        </form>
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
