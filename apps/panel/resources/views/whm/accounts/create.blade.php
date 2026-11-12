@extends('layouts.app')

@section('title', 'New hosting account')

@section('content')
    <h1>New hosting account</h1>
    <p class="small muted">This creates the Linux user, home directory, PHP-FPM pool, Apache vhost, quota and DNS zone in one step.</p>

    @if ($packages->isEmpty())
        <div class="alert warn">
            <x-icon name="alert" :size="16" />
            <div>No packages exist yet. <a href="{{ route('whm.packages.index') }}">Create a package</a> first — an account without limits is not allowed.</div>
        </div>
    @else
        <form method="POST" action="{{ route('whm.accounts.store') }}" class="card" style="max-width:760px">
            @csrf

            <div class="form-row cols">
                <div>
                    <label for="username">Username</label>
                    <input id="username" name="username" type="text" value="{{ old('username') }}" required autocomplete="off">
                    <div class="hint">Lowercase, starts with a letter, 3–16 characters. This is also the Linux user.</div>
                </div>

                <div>
                    <label for="domain">Primary domain</label>
                    <input id="domain" name="domain" type="text" value="{{ old('domain') }}" placeholder="acme.co.in" required>
                    <div class="hint">The site is created at /home/&lt;user&gt;/public_html.</div>
                </div>
            </div>

            <div class="form-row">
                <div>
                    <label for="package_id">Package</label>
                    <select id="package_id" name="package_id" required>
                        @foreach ($packages as $package)
                            <option value="{{ $package->id }}" @selected(old('package_id') == $package->id)>
                                {{ $package->name }} — {{ $package->diskLabel() }}, {{ number_format($package->inodes) }} inodes, PHP {{ $package->php_version }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="card" style="background:var(--surface-2)">
                <h3>Panel login for the customer</h3>
                <p class="small muted">Optional. If you enter an email, a login is created and a one-time password is shown once after saving.</p>

                <div class="form-row cols">
                    <div>
                        <label for="owner_email">Customer email</label>
                        <input id="owner_email" name="owner_email" type="email" value="{{ old('owner_email') }}" autocomplete="off">
                    </div>
                    <div>
                        <label for="owner_name">Customer name</label>
                        <input id="owner_name" name="owner_name" type="text" value="{{ old('owner_name') }}" autocomplete="off">
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div>
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" style="min-height:90px;font-family:inherit;font-size:.9rem">{{ old('notes') }}</textarea>
                </div>
            </div>

            <div class="row">
                <button class="btn primary" type="submit"><x-icon name="plus" :size="15" /> Create account</button>
                <a class="btn ghost" href="{{ route('whm.accounts.index') }}">Cancel</a>
            </div>
        </form>
    @endif
@endsection
