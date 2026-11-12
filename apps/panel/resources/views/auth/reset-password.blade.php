@extends('layouts.auth')

@section('title', 'Choose a new password')

@section('content')
    <h1>Choose a new password</h1>

    <form method="POST" action="{{ route('password.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div class="form-row">
            <div>
                <label for="email">Email address</label>
                <input id="email" name="email" type="email" value="{{ old('email', $request->email) }}" required>
            </div>
        </div>

        <div class="form-row">
            <div>
                <label for="password">New password</label>
                <input id="password" name="password" type="password" required autocomplete="new-password">
                <div class="hint">At least 12 characters, with upper and lower case letters and a number.</div>
            </div>
        </div>

        <div class="form-row">
            <div>
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
            </div>
        </div>

        <button class="btn primary" type="submit" style="width:100%;justify-content:center">Set password</button>
    </form>
@endsection
