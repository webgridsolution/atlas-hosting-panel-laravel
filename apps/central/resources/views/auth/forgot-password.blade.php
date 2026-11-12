<x-guest-layout heading="Reset your password"
                 subheading="We'll email a single-use reset link. It expires in 60 minutes.">
    @if (session('status'))
        <x-alert variant="ok" class="mb-2">{{ session('status') }}</x-alert>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="stack">
        @csrf
        <x-input label="Email address" name="email" type="email" required autofocus autocomplete="username" />
        <button type="submit" class="btn btn-primary btn-block">Email reset link</button>
    </form>

    <p class="auth-links" style="justify-content:center;margin-top:22px">
        <a href="{{ route('login') }}" class="link">Back to sign in</a>
    </p>
</x-guest-layout>
