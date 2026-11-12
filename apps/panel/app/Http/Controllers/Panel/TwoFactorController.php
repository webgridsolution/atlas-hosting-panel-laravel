<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Security\Totp;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Enrolling and removing a second factor.
 *
 * The sequence is the one every authenticated panel uses, because a half-done
 * enrolment is worse than none:
 *
 *   1. `start`   generates a secret and shows it — but does not arm it.
 *   2. `confirm` takes a code from the app. Only then is the factor live, and
 *                only then are recovery codes issued.
 *
 * Disabling needs the current password *and* — if the factor is live — a valid
 * code. A stolen session should not be able to turn the second factor off.
 */
class TwoFactorController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('panel.security.twofactor', [
            'user' => $user,
            'enabled' => $user->hasTwoFactor(),
            // The secret lives in the session between start and confirm. It is
            // never persisted on the user until a code has proven the app holds it.
            'pending' => $request->session()->get('two_factor.pending'),
            'recoveryCodes' => $request->session()->get('two_factor.recovery'),
        ]);
    }

    /** Generate a secret and show it. Nothing is armed yet. */
    public function start(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactor()) {
            return back()->with('error', 'Two-factor authentication is already on. Turn it off first to enrol a new device.');
        }

        $request->session()->put('two_factor.pending', Totp::generateSecret());

        return back()->with('status', 'Scan the key below with your authenticator app, then enter the six-digit code it shows.');
    }

    /** The code from the app is the proof the secret reached the device. */
    public function confirm(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'code' => ['required', 'string', 'max:10'],
        ]);

        $secret = (string) $request->session()->get('two_factor.pending');

        if ($secret === '') {
            return back()->with('error', 'Start the enrolment first — there is no key to confirm.');
        }

        if (! Totp::verify($secret, $data['code'])) {
            // Deliberately vague about whether it was the code or the clock.
            return back()->with('error', 'That code did not match. Check the time on your phone and try the next one.');
        }

        $codes = $this->newRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $request->session()->forget('two_factor.pending');

        // Shown once, in this response only.
        $request->session()->flash('two_factor.recovery', $codes);

        return redirect()->route('panel.security.twofactor')
            ->with('status', 'Two-factor authentication is on. Store the recovery codes below — they are shown once.');
    }

    /** Turn it off. Needs the password, and a code if the factor is live. */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check((string) $request->input('password'), (string) $user->password)) {
            return back()->with('error', 'That password is not correct.');
        }

        if ($user->hasTwoFactor()) {
            $request->validate([
                'code' => ['required', 'string', 'max:10'],
            ]);

            if (! Totp::verify((string) $user->two_factor_secret, (string) $request->input('code'))) {
                return back()->with('error', 'That two-factor code did not match.');
            }
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $request->session()->forget(['two_factor.pending', 'two_factor.recovery']);

        return redirect()->route('panel.security.twofactor')
            ->with('status', 'Two-factor authentication is off. Your password is the only factor again.');
    }

    /** @return array<int, string> */
    private function newRecoveryCodes(int $count = 8): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            // Grouped so they can be read off a screen and typed back.
            $codes[] = strtoupper(bin2hex(random_bytes(5)));
        }

        return $codes;
    }
}
