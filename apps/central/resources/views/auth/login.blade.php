<x-guest-layout heading="Sign in" subheading="Access your licences, servers and invoices.">
    @if (session('status'))
        <x-alert variant="ok" class="mb-2">{{ session('status') }}</x-alert>
    @endif

    <form method="POST" action="{{ route('login') }}" class="stack">
        @csrf

        <x-input label="Email address" name="email" type="email" required autofocus autocomplete="username" />

        <div>
            <x-input label="Password" name="password" type="password" required autocomplete="current-password" />
            <div class="auth-links">
                <x-checkbox name="remember" label="Keep me signed in" />
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="link">Forgot password?</a>
                @endif
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Sign in</button>
    </form>

    <p class="auth-links" style="justify-content:center;margin-top:22px">
        No account yet? <a href="{{ route('register') }}" class="link">Create one</a>
    </p>

    @if (app()->environment('local'))
        <div class="panel-flat mt-3">
            <div class="eyebrow mb-1">Preview accounts (local only)</div>
            <div class="small muted mono" style="line-height:1.9">
                admin@atlas.test · password<br>
                owner@atlas.test · password
            </div>
        </div>
    @endif
</x-guest-layout>
