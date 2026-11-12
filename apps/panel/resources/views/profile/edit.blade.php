@extends('layouts.app')

@section('title', 'Your profile')

@section('content')
    <h1>Your profile</h1>
    <p class="muted small">Panel logins are separate from the hosting accounts they manage.</p>

    <div class="grid two">
        <div class="card">
            <h2>Details</h2>
            <form method="POST" action="{{ route('profile.update') }}">
                @csrf
                @method('PATCH')

                <div class="form-row">
                    <div>
                        <label for="name">Name</label>
                        <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required>
                    </div>
                </div>

                <div class="form-row">
                    <div>
                        <label for="email">Email address</label>
                        <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required>
                    </div>
                </div>

                <button class="btn primary" type="submit">Save details</button>
            </form>
        </div>

        <div class="card">
            <h2>Password</h2>
            <form method="POST" action="{{ route('profile.password') }}">
                @csrf
                @method('PUT')

                <div class="form-row">
                    <div>
                        <label for="current_password">Current password</label>
                        <input id="current_password" name="current_password" type="password" required autocomplete="current-password">
                    </div>
                </div>

                <div class="form-row">
                    <div>
                        <label for="password">New password</label>
                        <input id="password" name="password" type="password" required autocomplete="new-password">
                        <div class="hint">12+ characters, mixed case, at least one number.</div>
                    </div>
                </div>

                <div class="form-row">
                    <div>
                        <label for="password_confirmation">Confirm new password</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
                    </div>
                </div>

                <button class="btn primary" type="submit">Change password</button>
            </form>
        </div>
    </div>

    <div class="card">
        <h2>Session</h2>
        <p class="small muted">Signed in as {{ $user->email }} · {{ $user->getRoleNames()->implode(', ') ?: 'no role' }}</p>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="btn" type="submit"><x-icon name="logout" :size="15" /> Sign out</button>
        </form>
    </div>
@endsection
