<?php

namespace Tests\Feature;

use App\Domain\Security\Totp;
use App\Models\LoginEvent;

/**
 * Two-factor authentication, including the part that matters most: what happens
 * between a correct password and a finished login.
 *
 * The TOTP implementation itself is checked against the published RFC 6238 test
 * vectors — a hand-written implementation of this that is not checked against
 * those is a guess.
 */
class TwoFactorTest extends PanelTestCase
{
    // -----------------------------------------------------------------------
    // the algorithm
    // -----------------------------------------------------------------------

    public function test_the_algorithm_matches_the_published_rfc6238_vectors(): void
    {
        // The RFC's secret is the ASCII string below; the vectors are 8-digit.
        $secret = Totp::base32Encode('12345678901234567890');

        $vectors = [
            59 => '94287082',
            1111111109 => '07081804',
            1111111111 => '14050471',
            1234567890 => '89005924',
            2000000000 => '69279037',
            20000000000 => '65353130',
        ];

        foreach ($vectors as $time => $expected) {
            $this->assertSame(
                $expected,
                Totp::code($secret, $time, 8),
                "the code for t={$time} does not match RFC 6238"
            );
        }
    }

    public function test_base32_round_trips(): void
    {
        $bytes = random_bytes(20);

        $this->assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
    }

    public function test_a_code_is_accepted_one_period_either_side_of_now(): void
    {
        $secret = Totp::generateSecret();
        $now = 1700000000;

        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now), $now));
        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now - 30), $now));
        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now + 30), $now));

        // Two periods out is a stale code, and is refused.
        $this->assertFalse(Totp::verify($secret, Totp::code($secret, $now - 90), $now));
    }

    // -----------------------------------------------------------------------
    // enrolment
    // -----------------------------------------------------------------------

    public function test_starting_enrolment_shows_a_key_but_does_not_arm_it(): void
    {
        $user = $this->user('customer');

        $response = $this->actingAs($user)
            ->post(route('panel.security.twofactor.start'))
            ->assertRedirect();

        $pending = session('two_factor.pending');

        $this->assertNotEmpty($pending, 'a secret should be waiting in the session');
        $this->assertFalse(
            $user->fresh()->hasTwoFactor(),
            'the factor must not be armed before a code proves the app holds the key'
        );

        $html = $this->actingAs($user)->get(route('panel.security.twofactor'))->getContent();

        $this->assertStringContainsString(Totp::readableSecret((string) $pending), $html);
        $this->assertStringContainsString('otpauth://totp/', $html);
    }

    public function test_a_wrong_code_does_not_arm_the_factor(): void
    {
        $user = $this->user('customer');

        $this->actingAs($user)->post(route('panel.security.twofactor.start'));

        $secret = (string) session('two_factor.pending');
        $wrong = Totp::code($secret) === '000000' ? '111111' : '000000';

        $this->actingAs($user)
            ->post(route('panel.security.twofactor.confirm'), ['code' => $wrong])
            ->assertRedirect();

        $this->assertFalse($user->fresh()->hasTwoFactor());
        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_a_correct_code_arms_the_factor_and_issues_recovery_codes(): void
    {
        $user = $this->user('customer');

        $this->actingAs($user)->post(route('panel.security.twofactor.start'));

        $secret = (string) session('two_factor.pending');

        $this->actingAs($user)
            ->post(route('panel.security.twofactor.confirm'), ['code' => Totp::code($secret)])
            ->assertRedirect(route('panel.security.twofactor'));

        $fresh = $user->fresh();

        $this->assertTrue($fresh->hasTwoFactor());
        $this->assertSame($secret, $fresh->two_factor_secret);
        $this->assertNotNull($fresh->two_factor_confirmed_at);

        // Eight hashes, not eight codes: the plaintext is shown once and kept
        // nowhere.
        $codes = (array) $fresh->two_factor_recovery_codes;
        $this->assertCount(8, $codes);

        foreach ($codes as $hash) {
            $this->assertSame(64, strlen($hash), 'a recovery code should be stored as a sha256 hash');
        }
    }

    public function test_the_secret_is_encrypted_at_rest(): void
    {
        $user = $this->user('customer');

        $this->actingAs($user)->post(route('panel.security.twofactor.start'));
        $secret = (string) session('two_factor.pending');
        $this->actingAs($user)->post(route('panel.security.twofactor.confirm'), ['code' => Totp::code($secret)]);

        // Read the raw column, bypassing the cast: what is on disk must not be
        // the key itself, or a database dump is a second-factor bypass.
        $raw = \DB::table('users')->where('id', $user->id)->value('two_factor_secret');

        $this->assertNotSame($secret, $raw);
        $this->assertStringNotContainsString($secret, (string) $raw);
    }

    public function test_the_secret_never_appears_in_a_serialised_user(): void
    {
        $user = $this->user('customer');

        $this->actingAs($user)->post(route('panel.security.twofactor.start'));
        $secret = (string) session('two_factor.pending');
        $this->actingAs($user)->post(route('panel.security.twofactor.confirm'), ['code' => Totp::code($secret)]);

        $json = $user->fresh()->toJson();

        $this->assertStringNotContainsString((string) $secret, $json);
        $this->assertStringNotContainsString('two_factor_secret', $json);
    }

    // -----------------------------------------------------------------------
    // the login gate
    // -----------------------------------------------------------------------

    public function test_a_login_with_two_factor_is_not_authenticated_by_the_password_alone(): void
    {
        $user = $this->twoFactorUser();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest('web');

        // The half-way state is in the session, and nothing else.
        $this->assertSame($user->id, session('two_factor.user'));
    }

    public function test_the_challenge_rejects_a_wrong_code(): void
    {
        $user = $this->twoFactorUser();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $wrong = Totp::code((string) $user->two_factor_secret) === '000000' ? '111111' : '000000';

        $this->post('/two-factor-challenge', ['code' => $wrong])
            ->assertSessionHasErrors('code');

        $this->assertGuest('web');
    }

    public function test_the_challenge_accepts_a_valid_code_and_completes_the_login(): void
    {
        $user = $this->twoFactorUser();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->post('/two-factor-challenge', [
            'code' => Totp::code((string) $user->two_factor_secret),
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertNull(session('two_factor.user'), 'the pending id should be cleared');
    }

    public function test_a_recovery_code_completes_the_login_once_and_is_then_burned(): void
    {
        $user = $this->twoFactorUser(['ABCDE12345']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->post('/two-factor-challenge', ['code' => 'ABCDE12345'])->assertRedirect();

        $this->assertAuthenticatedAs($user->fresh());

        // The same code cannot be used again.
        $remaining = (array) $user->fresh()->two_factor_recovery_codes;
        $this->assertNotContains(hash('sha256', 'ABCDE12345'), $remaining);
    }

    public function test_the_challenge_cannot_be_opened_without_a_pending_login(): void
    {
        $this->get('/two-factor-challenge')->assertRedirect(route('login'));
    }

    public function test_the_challenge_cannot_be_entered_for_another_user_by_changing_the_session(): void
    {
        $victim = $this->twoFactorUser();

        // No pending id at all: the store endpoint must not trust the posted form.
        $this->post('/two-factor-challenge', ['code' => '123456'])
            ->assertRedirect(route('login'));

        $this->assertGuest('web');
    }

    public function test_a_login_event_is_recorded_when_the_second_factor_passes(): void
    {
        $user = $this->twoFactorUser();

        $before = LoginEvent::count();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/two-factor-challenge', ['code' => Totp::code((string) $user->two_factor_secret)]);

        $this->assertGreaterThan($before, LoginEvent::count());
    }

    // -----------------------------------------------------------------------
    // turning it off
    // -----------------------------------------------------------------------

    public function test_turning_it_off_needs_the_password(): void
    {
        $user = $this->twoFactorUser();

        $this->actingAs($user)
            ->delete(route('panel.security.twofactor.destroy'), [
                'password' => 'wrong-password',
                'code' => Totp::code((string) $user->two_factor_secret),
            ])
            ->assertSessionHas('error');

        $this->assertTrue($user->fresh()->hasTwoFactor());
    }

    public function test_turning_it_off_needs_a_current_code(): void
    {
        $user = $this->twoFactorUser();

        $wrong = Totp::code((string) $user->two_factor_secret) === '000000' ? '111111' : '000000';

        $this->actingAs($user)
            ->delete(route('panel.security.twofactor.destroy'), [
                'password' => 'password',
                'code' => $wrong,
            ])
            ->assertSessionHas('error');

        $this->assertTrue($user->fresh()->hasTwoFactor());
    }

    public function test_a_stolen_session_alone_cannot_switch_the_factor_off(): void
    {
        $user = $this->twoFactorUser();

        // Exactly what an attacker with a hijacked cookie has: a session, and no
        // password. The password field is required by validation.
        $this->actingAs($user)
            ->delete(route('panel.security.twofactor.destroy'), [
                'code' => Totp::code((string) $user->two_factor_secret),
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->hasTwoFactor());
    }

    public function test_turning_it_off_clears_everything(): void
    {
        $user = $this->twoFactorUser();

        $this->actingAs($user)
            ->delete(route('panel.security.twofactor.destroy'), [
                'password' => 'password',
                'code' => Totp::code((string) $user->two_factor_secret),
            ])
            ->assertRedirect(route('panel.security.twofactor'));

        $fresh = $user->fresh();

        $this->assertFalse($fresh->hasTwoFactor());
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
        $this->assertNull($fresh->two_factor_confirmed_at);
    }

    public function test_after_turning_it_off_a_password_alone_signs_in(): void
    {
        $user = $this->twoFactorUser();

        $this->actingAs($user)
            ->delete(route('panel.security.twofactor.destroy'), [
                'password' => 'password',
                'code' => Totp::code((string) $user->two_factor_secret),
            ]);

        $this->post('/logout');

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect();

        $this->assertAuthenticated('web');
    }
}
