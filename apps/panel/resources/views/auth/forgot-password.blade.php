@extends('layouts.auth')

@section('title', 'Reset password')

@section('content')
    <h1>Reset your password</h1>
    <p class="small">Enter the email address on your account and we will send a reset link.</p>

    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <div class="form-row">
            <div>
                <label for="email">Email address</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
            </div>
        </div>

        <button class="btn primary" type="submit" style="width:100%;justify-content:center">Email me a reset link</button>
    </form>

    <p class="foot"><a href="{{ route('login') }}">Back to sign in</a></p>
@endsection
