<x-guest-layout heading="Choose a new password" subheading="This link can only be used once.">
    <form method="POST" action="{{ route('password.store') }}" class="stack">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-input label="Email address" name="email" type="email" :value="$request->email" required autocomplete="username" />
        <x-input label="New password" name="password" type="password" required autocomplete="new-password" />
        <x-input label="Confirm new password" name="password_confirmation" type="password" required autocomplete="new-password" />

        <button type="submit" class="btn btn-primary btn-block">Reset password</button>
    </form>
</x-guest-layout>
