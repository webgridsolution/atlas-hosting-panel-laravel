<x-guest-layout heading="Verify your email"
                 subheading="We sent a verification link to your inbox. Click it to unlock your portal.">
    @if (session('status') == 'verification-link-sent')
        <x-alert variant="ok" class="mb-2">A fresh verification link has been sent.</x-alert>
    @endif

    <div class="stack">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="btn btn-primary btn-block">Resend verification email</button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-ghost btn-block">Sign out</button>
        </form>
    </div>
</x-guest-layout>
