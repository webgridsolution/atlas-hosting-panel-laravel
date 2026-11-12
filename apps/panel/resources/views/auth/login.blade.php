@extends('layouts.auth')

@section('title', 'Sign in')

@section('content')
    <h1>Sign in</h1>
    <p class="small">Use the credentials your hosting provider or reseller gave you.</p>

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="form-row">
            <div>
                <label for="email">Email address</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            </div>
        </div>

        <div class="form-row">
            <div>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password">
            </div>
        </div>

        <div class="spread" style="margin-bottom:18px">
            <label class="checkbox" style="margin:0">
                <input type="checkbox" name="remember"> Remember this browser
            </label>
            <a class="small" href="{{ route('password.request') }}">Forgot password?</a>
        </div>

        <button class="btn primary" type="submit" style="width:100%;justify-content:center">Sign in</button>
    </form>

    <p class="foot">
        This panel does not offer self-registration: hosting accounts are created by an administrator or reseller.
    </p>
@endsection
