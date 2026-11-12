<?php

namespace Tests\Feature;

use App\Domain\Licensing\LicenseManager;
use App\Models\Licence;
use App\Models\Setting;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The panel-to-vendor contract.
 *
 * These tests exist because the first implementation called an endpoint the
 * vendor does not have (`/api/v1/license/verify`) with a payload the vendor does
 * not accept. Nothing failed loudly: the call 404'd, was swallowed as "vendor
 * unreachable", and the panel carried on with a stale token until it expired.
 * A contract test is the only thing that catches that class of bug.
 */
class LicenceSyncTest extends PanelTestCase
{
    private const VENDOR = 'https://vendor.test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['atlas.central.url' => self::VENDOR]);
    }

    public function test_activating_a_key_sends_the_documented_payload_and_stores_the_signed_token(): void
    {
        $token = $this->mintToken(['exp' => time() + 30 * 86400]);

        Http::fake([
            self::VENDOR.'/api/v1/license/activate' => Http::response([
                'status' => 'active',
                'read_only' => false,
                'expires_at' => now()->addDays(30)->toIso8601String(),
                'grace_days' => 14,
                'token' => $token,
                'kid' => 'test-kid',
            ]),
        ]);

        $response = $this->actingAs($this->user('admin'))->post(route('whm.licence.activate'), [
            'key' => 'ATLS-7F3K-9QWD-2MXB',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        Http::assertSent(function (Request $request) {
            return $request->url() === self::VENDOR.'/api/v1/license/activate'
                && $request['key'] === 'ATLS-7F3K-9QWD-2MXB'
                && is_string($request['fingerprint']) && strlen($request['fingerprint']) === 64
                && $request->hasHeader('Accept', 'application/json');
        });

        $licence = Licence::current();

        $this->assertSame($token, $licence->token);
        $this->assertSame('ATLS-7F3K-9QWD-2MXB', $licence->licence_key);
        $this->assertNotNull($licence->expires_at);
    }

    public function test_a_refused_key_changes_nothing(): void
    {
        $before = Licence::current()->token;

        Http::fake([
            self::VENDOR.'/api/v1/license/activate' => Http::response([
                'error' => ['code' => 'ACTIVATION_LIMIT_REACHED', 'message' => 'This key is already active on another server.'],
            ], 409),
        ]);

        $this->actingAs($this->user('admin'))
            ->post(route('whm.licence.activate'), ['key' => 'ATLS-0000-0000-0000'])
            ->assertSessionHas('error', 'This key is already active on another server.');

        $after = Licence::current();
        $this->assertSame($before, $after->token);
        $this->assertNull($after->licence_key);
    }

    public function test_an_unverifiable_token_from_the_vendor_is_rejected(): void
    {
        $foreign = sodium_crypto_sign_keypair();
        $body = $this->b64url(json_encode(['kid' => 'someone-else', 'exp' => time() + 86400]));
        $forged = 'atlas-lic-v1.'.$body.'.'.$this->b64url(
            sodium_crypto_sign_detached('atlas-lic-v1.'.$body, sodium_crypto_sign_secretkey($foreign))
        );

        Http::fake([
            self::VENDOR.'/api/v1/license/activate' => Http::response(['token' => $forged, 'kid' => 'someone-else']),
        ]);

        $before = Licence::current()->token;

        $this->actingAs($this->user('admin'))
            ->post(route('whm.licence.activate'), ['key' => 'ATLS-1111-2222-3333'])
            ->assertSessionHas('error');

        $this->assertSame($before, Licence::current()->token);
    }

    public function test_refresh_uses_the_stored_key_and_the_heartbeat_endpoint(): void
    {
        $licence = $this->licensedKey();

        $fresh = $this->mintToken(['exp' => time() + 60 * 86400]);

        Http::fake([
            self::VENDOR.'/api/v1/license/heartbeat' => Http::response([
                'status' => 'active',
                'read_only' => false,
                'token' => $fresh,
                'kid' => 'test-kid',
            ]),
        ]);

        $this->actingAs($this->user('admin'))
            ->post(route('whm.licence.refresh'))
            ->assertSessionHas('status');

        Http::assertSent(fn (Request $request) => $request->url() === self::VENDOR.'/api/v1/license/heartbeat'
            && $request['key'] === 'ATLS-7F3K-9QWD-2MXB'
            && $request['fingerprint'] === $licence->fingerprint);

        $this->assertSame($fresh, Licence::current()->token);
    }

    public function test_a_refresh_that_fails_keeps_the_last_known_good_token(): void
    {
        $licence = $this->licensedKey();
        $before = $licence->token;

        Http::fake([
            self::VENDOR.'/api/v1/license/heartbeat' => Http::response(['error' => 'upstream down'], 500),
        ]);

        $this->actingAs($this->user('admin'))
            ->post(route('whm.licence.refresh'))
            ->assertSessionHas('error');

        $after = Licence::current();
        $this->assertSame($before, $after->token, 'a failed refresh must never drop a working token');
        $this->assertNotNull($after->last_error);
    }

    public function test_refresh_without_a_key_says_so_instead_of_calling_the_vendor(): void
    {
        Http::fake();

        $this->actingAs($this->user('admin'))
            ->post(route('whm.licence.refresh'))
            ->assertSessionHas('error', 'No licence key is installed on this server. Activate a key first.');

        Http::assertNothingSent();
    }

    public function test_a_revocation_from_the_vendor_turns_writes_off_without_deleting_anything(): void
    {
        $this->licensedKey();
        $account = $this->account($this->user('customer'));

        $revoked = $this->mintToken(['sts' => 'revoked']);

        Http::fake([
            self::VENDOR.'/api/v1/license/heartbeat' => Http::response(['token' => $revoked, 'kid' => 'test-kid']),
        ]);

        $this->actingAs($this->user('admin'))->post(route('whm.licence.refresh'));

        $this->assertSame(Licence::READ_ONLY, app(LicenseManager::class)->evaluate()['state']);
        $this->assertDatabaseHas('accounts', ['id' => $account->id, 'status' => 'active']);
    }

    public function test_entitlements_come_from_the_vendors_ent_claim(): void
    {
        $this->licensedKey();

        $token = $this->mintToken(['ent' => ['mail' => true, 'dns' => false, 'backups' => true]]);

        Http::fake([
            self::VENDOR.'/api/v1/license/heartbeat' => Http::response(['token' => $token, 'kid' => 'test-kid']),
        ]);

        $this->actingAs($this->user('admin'))->post(route('whm.licence.refresh'));

        $manager = app(LicenseManager::class);

        $this->assertTrue($manager->feature('mail'));
        $this->assertTrue($manager->feature('backups'));
        $this->assertFalse($manager->feature('dns'));
        $this->assertSame(['mail' => true, 'dns' => false, 'backups' => true], Licence::current()->features);
    }

    public function test_a_suspended_licence_is_read_only(): void
    {
        $this->licensedKey();

        $token = $this->mintToken(['sts' => 'suspended']);

        Http::fake([
            self::VENDOR.'/api/v1/license/heartbeat' => Http::response(['token' => $token, 'kid' => 'test-kid']),
        ]);

        $this->actingAs($this->user('admin'))->post(route('whm.licence.refresh'));

        $this->assertSame(Licence::READ_ONLY, app(LicenseManager::class)->evaluate()['state']);
    }

    public function test_the_fingerprint_is_stable_across_calls(): void
    {
        $manager = app(LicenseManager::class);

        $first = $manager->fingerprint();

        Setting::put('server_fingerprint', null);

        $this->assertSame($first, $manager->fingerprint());
        $this->assertSame(64, strlen($first));
    }

    /** Gives the panel a stored key + fingerprint, as a real activation would. */
    private function licensedKey(): Licence
    {
        $licence = Licence::current();
        $licence->forceFill([
            'licence_key' => 'ATLS-7F3K-9QWD-2MXB',
            'fingerprint' => app(LicenseManager::class)->fingerprint(),
        ])->save();

        return $licence->fresh();
    }
}
