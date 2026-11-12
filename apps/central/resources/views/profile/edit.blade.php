<x-app-layout title="Account settings" :crumbs="[['label' => 'Account settings']]">
    <div class="page-head">
        <div>
            <h1>Account settings</h1>
            <p>Your login details. Billing identity (company, GSTIN) is added with the invoicing module.</p>
        </div>
    </div>

    <div class="grid cols-2" style="gap:16px;align-items:start">
        <div class="card">
            <div class="card-head"><h2>Profile</h2></div>
            <form method="POST" action="{{ route('profile.update') }}" class="card-body">
                @csrf
                @method('patch')

                <x-input label="Name" name="name" :value="$user->name" required autocomplete="name" />
                <div class="mt-2">
                    <x-input label="Email address" name="email" type="email" :value="$user->email" required autocomplete="username"
                             hint="Changing this requires re-verification." />
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save changes</button>
                    @if (session('status') === 'profile-updated')
                        <span class="small brand-text">Saved.</span>
                    @endif
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-head"><h2>Password</h2></div>
            <form method="POST" action="{{ route('password.update') }}" class="card-body">
                @csrf
                @method('put')

                <x-input label="Current password" name="current_password" type="password" autocomplete="current-password" />
                <div class="mt-2">
                    <x-input label="New password" name="password" type="password" autocomplete="new-password" />
                </div>
                <div class="mt-2">
                    <x-input label="Confirm new password" name="password_confirmation" type="password" autocomplete="new-password" />
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Update password</button>
                    @if (session('status') === 'password-updated')
                        <span class="small brand-text">Password updated.</span>
                    @endif
                </div>
            </form>
        </div>
    </div>

    <div class="card mt-3" style="border-color:color-mix(in srgb, var(--danger) 30%, var(--line))">
        <div class="card-head">
            <h2 style="color:var(--danger)">Delete account</h2>
        </div>
        <form method="POST" action="{{ route('profile.destroy') }}" class="card-body"
              data-confirm="Delete this account permanently? Active licences must be cancelled first.">
            @csrf
            @method('delete')

            <p class="small muted" style="max-width:62ch">
                Your account and personal data are removed. Licence records and invoices are retained for statutory periods.
                This cannot be undone.
            </p>

            <div class="mt-2" style="max-width:320px">
                <x-input label="Confirm with your password" name="password" type="password" autocomplete="current-password" />
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-danger">Delete account</button>
            </div>
        </form>
    </div>
</x-app-layout>
