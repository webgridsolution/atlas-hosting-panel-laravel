<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Security\Totp;
use App\Http\Controllers\Controller;
use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * The second step of a login.
 *
 * Reached only with a `two_factor.user` in the session — put there by
 * AuthenticatedSessionController after the password was right. Nothing here
 * trusts that id as authentication: it is a claim of "this person knows the
 * password", and it is only turned into a session once a code proves the device.
 *
 * A recovery code is accepted once and then burned, because that is the point of
 * a recovery code: it is a way back in when the phone is gone, not a spare
 * password.
 */
class TwoFactorChallengeController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('two_factor.user')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $pendingId = $request->session()->get('two_factor.user');

        if (! $pendingId) {
            return redirect()->route('login');
        }

        $request->validate([
            'code' => ['required', 'string', 'max:20'],
        ]);

        // Same throttle key shape as the password step, so guessing codes costs
        // the same as guessing passwords.
        $key = 'two-factor:'.$pendingId.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors([
                'code' => 'Too many attempts. Wait a minute before trying again.',
            ]);
        }

        $user = User::find($pendingId);

        if (! $user instanceof User || ! $user->hasTwoFactor() || ! $user->is_active) {
            $request->session()->forget('two_factor.user');

            return redirect()->route('login')->withErrors([
                'email' => 'That login can no longer complete a two-factor check.',
            ]);
        }

        $code = (string) $request->input('code');

        $passed = Totp::verify((string) $user->two_factor_secret, $code);

        if (! $passed) {
            $passed = $this->consumeRecoveryCode($user, $code);
        }

        if (! $passed) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['code' => 'That code did not match.']);
        }

        RateLimiter::clear($key);

        $request->session()->forget('two_factor.user');
        $request->session()->regenerate();

        auth()->login($user, remember: false);

        LoginEvent::create([
            'user_id' => $user->id,
            'account_id' => $user->account()?->id,
            'area' => 'panel',
            'email' => $user->email,
            'ip' => (string) $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'successful' => true,
        ]);

        return redirect()->intended(route($user->homeRoute(), absolute: false));
    }

    /**
     * Try to spend a recovery code.
     *
     * Stored as SHA-256 hashes and compared with hash_equals. A match removes
     * the hash from the list, so the same code cannot be used twice.
     */
    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $candidate = hash('sha256', strtoupper(trim($code)));

        $codes = (array) ($user->two_factor_recovery_codes ?? []);

        foreach ($codes as $index => $hash) {
            if (is_string($hash) && hash_equals($hash, $candidate)) {
                unset($codes[$index]);

                $user->forceFill([
                    'two_factor_recovery_codes' => array_values($codes),
                ])->save();

                return true;
            }
        }

        return false;
    }
}
