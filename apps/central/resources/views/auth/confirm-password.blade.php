<x-guest-layout heading="Confirm your password"
                 subheading="This is a protected area. Re-enter your password to continue.">
    <form method="POST" action="{{ route('password.confirm') }}" class="stack">
        @csrf
        <x-input label="Password" name="password" type="password" required autofocus autocomplete="current-password" />
        <button type="submit" class="btn btn-primary btn-block">Confirm</button>
    </form>
</x-guest-layout>
