@extends('layouts.auth')

@section('title', 'Two-factor check')

@section('content')
    <h1>One more step</h1>
    <p class="small">
        Your password was correct. Enter the six-digit code from your authenticator app to finish signing in.
    </p>

    <form method="POST" action="{{ route('two-factor.challenge.store') }}">
        @csrf

        <div class="form-row">
            <div>
                <label for="code">Authentication code</label>
                <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                       maxlength="20" required autofocus
                       placeholder="123456"
                       style="font-family:var(--mono);letter-spacing:.24em;text-align:center;font-size:1.15rem">
                <div class="field-hint">Lost your phone? Enter one of your recovery codes instead.</div>
            </div>
        </div>

        @if ($errors->any())
            <div class="alert mb-4" role="alert">
                <x-icon name="alert" :size="16" />
                <div>{{ $errors->first() }}</div>
            </div>
        @endif

        <button class="btn primary" type="submit" style="width:100%;justify-content:center">
            Verify and sign in
        </button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-4" style="text-align:center">
        @csrf
        <button class="btn ghost sm" type="submit">Cancel and start over</button>
    </form>

    <p class="foot">
        This step is required because two-factor authentication is enabled on this login.
    </p>
@endsection
