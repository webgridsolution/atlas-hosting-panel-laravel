@extends('layouts.app')

@section('title', 'New customer')

@section('content')
    <h1>New customer</h1>
    <p class="small muted">Creates the hosting account and a customer login under your reseller scope.</p>

    @if ($packages->isEmpty())
        <div class="alert warn"><x-icon name="alert" :size="16" /><div>No packages are available. Ask the administrator to create one.</div></div>
    @else
        <form method="POST" action="{{ route('reseller.accounts.store') }}" class="card" style="max-width:700px">
            @csrf

            <div class="form-row cols">
                <div>
                    <label for="username">Username</label>
                    <input id="username" name="username" type="text" value="{{ old('username') }}" required autocomplete="off">
                    <div class="hint">Lowercase letters and digits, 3–16 characters.</div>
                </div>
                <div>
                    <label for="domain">Primary domain</label>
                    <input id="domain" name="domain" type="text" value="{{ old('domain') }}" placeholder="example.com" required>
                </div>
            </div>

            <div class="form-row">
                <div>
                    <label for="package_id">Package</label>
                    <select id="package_id" name="package_id" required>
                        @foreach ($packages as $package)
                            <option value="{{ $package->id }}" @selected(old('package_id') == $package->id)>
                                {{ $package->name }} — {{ $package->diskLabel() }} · PHP {{ $package->php_version }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div>
                    <label for="notes">Notes</label>
                    <input id="notes" name="notes" type="text" value="{{ old('notes') }}" placeholder="Order reference, contact person…">
                </div>
            </div>

            <div class="row">
                <button class="btn primary" type="submit">Create customer</button>
                <a class="btn ghost" href="{{ route('reseller.accounts.index') }}">Cancel</a>
            </div>
        </form>
    @endif
@endsection
