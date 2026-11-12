@extends('layouts.auth')

@section('title', 'Confirm password')

@section('content')
    <h1>Confirm your password</h1>
    <p class="small">This is a protected area. Please confirm your password to continue.</p>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf
        <div class="form-row">
            <div>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password">
            </div>
        </div>

        <button class="btn primary" type="submit" style="width:100%;justify-content:center">Confirm</button>
    </form>
@endsection
