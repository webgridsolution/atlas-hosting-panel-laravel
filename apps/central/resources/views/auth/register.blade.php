<x-guest-layout heading="Create your account" subheading="Buy a licence, then install the panel on your own VPS.">
    <form method="POST" action="{{ route('register') }}" class="stack">
        @csrf

        <x-input label="Full name" name="name" required autofocus autocomplete="name" />

        <x-input label="Email address" name="email" type="email" required autocomplete="username"
                 hint="Licence keys and invoices are sent here." />

        <x-input label="Password" name="password" type="password" required autocomplete="new-password"
                 hint="At least 8 characters. Use a password manager." />

        <x-input label="Confirm password" name="password_confirmation" type="password" required autocomplete="new-password" />

        <label class="checkbox-row">
            <input type="checkbox" name="terms" required>
            <span>I agree to the <a href="{{ route('legal') }}#terms" class="link">terms of service</a> and understand one licence covers one server.</span>
        </label>
        @error('terms')
            <span class="error-text">{{ $message }}</span>
        @enderror

        <button type="submit" class="btn btn-primary btn-block">Create account</button>
    </form>

    <p class="auth-links" style="justify-content:center;margin-top:22px">
        Already registered? <a href="{{ route('login') }}" class="link">Sign in</a>
    </p>
</x-guest-layout>
