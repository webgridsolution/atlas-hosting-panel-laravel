<?php

namespace Tests\Feature;

use App\Domain\Licensing\LicenseManager;
use App\Models\Account;
use App\Models\Licence;
use App\Models\Setting;

/**
 * Licensing decides what the panel will and will not do. The rules under test:
 *
 *   active / grace  everything works
 *   read_only       no writes, existing sites keep serving, data is never deleted
 *   unlicensed      same as read_only
 *
 * The failure this guards against is the obvious one: an expired licence
 * quietly suspending a customer's website, or worse, deleting it.
 */
class LicenseModeTest extends PanelTestCase
{
    private function manager(): LicenseManager
    {
        return app(LicenseManager::class);
    }

    private function storeToken(string $token): void
    {
        Licence::query()->delete();
        cache()->forget('licence:evaluate');

        $claim = $this->manager()->verify($token);
        $this->assertIsArray($claim, 'the test token must verify');

        Licence::create([
            'token' => $token,
            'kid' => 'test-kid',
            'state' => 'active',
            'last_checked_at' => now(),
        ]);

        cache()->forget('licence:evaluate');
    }

    private function clearLicence(): void
    {
        Licence::query()->delete();
        Setting::put('licence_token', null);
        cache()->forget('licence:evaluate');
    }

    public function test_a_signed_active_token_is_active(): void
    {
        $this->assertSame(Licence::ACTIVE, $this->manager()->evaluate()['state']);
        $this->assertTrue($this->manager()->allowsWrites());
    }

    public function test_an_install_with_no_licence_is_unlicensed_and_read_only(): void
    {
        $this->clearLicence();

        $state = $this->manager()->evaluate();

        $this->assertSame(Licence::UNLICENSED, $state['state']);
        $this->assertFalse($this->manager()->allowsWrites());
    }

    public function test_a_tampered_token_is_read_only(): void
    {
        $token = $this->mintToken();
        $parts = explode('.', $token);

        // Flip one character of the payload: the signature no longer matches.
        $payload = $parts[1];
        $payload[10] = $payload[10] === 'A' ? 'B' : 'A';

        $this->storeTokenQuietly($parts[0].'.'.$payload.'.'.$parts[2]);

        $this->assertSame(Licence::READ_ONLY, $this->manager()->evaluate()['state']);
    }

    public function test_a_token_signed_by_an_unknown_key_is_read_only(): void
    {
        $foreign = sodium_crypto_sign_keypair();
        $body = $this->b64url(json_encode(['kid' => 'not-ours', 'exp' => time() + 86400]));
        $token = 'atlas-lic-v1.'.$body.'.'.$this->b64url(
            sodium_crypto_sign_detached('atlas-lic-v1.'.$body, sodium_crypto_sign_secretkey($foreign))
        );

        $this->storeTokenQuietly($token);

        $this->assertSame(Licence::READ_ONLY, $this->manager()->evaluate()['state']);
    }

    public function test_an_expired_token_is_in_grace_not_dead(): void
    {
        $this->clearLicence();
        $this->storeToken($this->mintToken(expiresIn: time() - 86400));

        $state = $this->manager()->evaluate();

        $this->assertSame(Licence::GRACE, $state['state']);
        $this->assertTrue($this->manager()->allowsWrites(), 'grace must still allow normal operation');
        $this->assertLessThanOrEqual(14, $state['days_left']);
    }

    public function test_after_grace_it_is_read_only_but_sites_keep_serving(): void
    {
        $this->clearLicence();
        $this->storeToken($this->mintToken(expiresIn: time() - 40 * 86400));

        $owner = $this->user('customer');
        $account = $this->account($owner);
        $site = $account->domains()->create([
            'domain' => 'keeps-serving.test',
            'document_root' => $account->docRoot(),
            'is_primary' => true,
        ]);

        $this->assertSame(Licence::READ_ONLY, $this->manager()->evaluate()['state']);

        // Nothing is suspended, deleted or hidden.
        $this->assertSame(Account::STATUS_ACTIVE, $account->fresh()->status);
        $this->assertNull($site->fresh()->deleted_at);

        // The customer can still log in and see their site.
        $this->actingAs($owner)->get(route('panel.dashboard'))->assertOk();
    }

    public function test_a_revoked_licence_is_read_only_immediately(): void
    {
        $this->clearLicence();
        $this->storeToken($this->mintToken(['sts' => 'revoked']));

        $this->assertSame(Licence::READ_ONLY, $this->manager()->evaluate()['state']);
    }

    public function test_writes_are_blocked_in_read_only_mode_but_reads_are_not(): void
    {
        $this->clearLicence();

        $admin = $this->user('admin');

        // Reading the console still works: that is how an operator fixes this.
        $this->actingAs($admin)->get(route('whm.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('whm.accounts.index'))->assertOk();

        // Creating an account does not, and nothing reaches the agent.
        $this->actingAs($admin)->post(route('whm.accounts.store'), [
            'username' => 'nolicence',
            'domain' => 'nolicence.test',
            'package_id' => $this->package()->id,
        ])->assertSessionHas('error');

        $this->assertFalse($this->agent->called('account.create'));
        $this->assertDatabaseMissing('accounts', ['username' => 'nolicence']);
    }

    public function test_writes_are_blocked_for_resellers_too(): void
    {
        $this->clearLicence();

        $reseller = $this->user('reseller');

        $this->actingAs($reseller)->post(route('reseller.accounts.store'), [
            'username' => 'blocked1',
            'domain' => 'blocked.test',
            'package_id' => $this->package()->id,
        ])->assertSessionHas('error');

        $this->assertFalse($this->agent->called('account.create'));
    }

    public function test_writes_are_blocked_for_customers_too(): void
    {
        $this->clearLicence();

        $owner = $this->user('customer');
        $this->account($owner);

        $this->actingAs($owner)->post(route('panel.websites.store'), [
            'domain' => 'new.test',
        ])->assertSessionHas('error');

        $this->assertFalse($this->agent->called('vhost.write'));
    }

    public function test_a_licence_can_still_be_activated_while_read_only(): void
    {
        // Otherwise an expired panel becomes a dead end: the one action that can
        // restore service must not be behind the gate that service needs.
        $this->clearLicence();

        $admin = $this->user('admin');

        $this->actingAs($admin)->get(route('whm.licence.index'))->assertOk();

        $response = $this->actingAs($admin)->post(route('whm.licence.store'), [
            'token' => $this->mintToken(),
        ]);

        $response->assertSessionHas('status');
        $this->assertSame(Licence::ACTIVE, $this->manager()->evaluate()['state']);
    }

    public function test_the_clock_moving_backwards_does_not_extend_or_break_the_licence(): void
    {
        $licence = Licence::current();
        $licence->forceFill(['last_checked_at' => now()->addDays(3)])->save();
        cache()->forget('licence:evaluate');

        $this->assertSame(Licence::READ_ONLY, $this->manager()->evaluate()['state']);
    }

    public function test_grace_days_is_configurable(): void
    {
        Setting::put('licence_grace_days', '30');
        $this->assertSame(30, $this->manager()->graceDays());

        Setting::put('licence_grace_days', null);
        $this->assertSame((int) config('atlas.central.grace_days'), $this->manager()->graceDays());
    }

    public function test_every_screen_still_renders_in_read_only_mode(): void
    {
        $this->clearLicence();

        $admin = $this->user('admin');

        foreach (['whm.dashboard', 'whm.accounts.index', 'whm.packages.index', 'whm.services.index', 'whm.audit.index', 'whm.settings.index', 'whm.licence.index'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
        }
    }

    public function test_health_reports_the_licence_state_without_revealing_the_token(): void
    {
        $this->clearLicence();

        $response = $this->getJson(route('health'));

        $response->assertOk()->assertJson(['ok' => true, 'licence' => 'unlicensed']);
        $this->assertStringNotContainsString('atlas-lic-v1', $response->getContent());
    }

    /** Stores a token the manager is expected to reject. */
    private function storeTokenQuietly(string $token): void
    {
        Licence::query()->delete();

        Licence::create([
            'token' => $token,
            'kid' => 'test-kid',
            'state' => 'active',
            'last_checked_at' => now(),
        ]);

        cache()->forget('licence:evaluate');
    }
}
