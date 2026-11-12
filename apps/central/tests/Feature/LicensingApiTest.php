<?php

namespace Tests\Feature;

use App\Domain\Licensing\IssueLicense;
use App\Domain\Licensing\TokenService;
use App\Enums\LicenseStatus;
use App\Models\Customer;
use App\Models\License;
use App\Models\LicenseKey;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LicensingApiTest extends TestCase
{
    use RefreshDatabase;

    private License $license;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleAndPermissionSeeder::class, FeatureSeeder::class, PlanSeeder::class]);

        $user = User::factory()->create();
        $user->assignRole('customer');
        $customer = Customer::create(['user_id' => $user->id, 'country' => 'IN']);

        $plan = Plan::with('entitlements')->where('slug', 'professional')->firstOrFail();
        $this->license = app(IssueLicense::class)->handle($customer, $plan, 'monthly');
    }

    private function activate(array $overrides = [])
    {
        return $this->postJson('/api/v1/license/activate', array_merge([
            'key' => $this->license->key,
            'fingerprint' => hash('sha256', 'server-one'),
            'hostname' => 'web-01.example.com',
            'os' => 'ubuntu-24.04',
            'version' => '1.4.0',
        ], $overrides));
    }

    public function test_activation_returns_a_token_that_verifies_offline(): void
    {
        $response = $this->activate()->assertOk();

        $token = $response->json('token');
        $this->assertNotEmpty($token);

        $claims = app(TokenService::class)->verify($token);

        $this->assertIsArray($claims);
        $this->assertSame($this->license->public_id, $claims['lic']);
        $this->assertSame('professional', $claims['ed']);
        $this->assertSame('active', $claims['sts']);
        $this->assertSame(50, $claims['ent']['max_accounts']);
    }

    public function test_activation_marks_the_licence_active_and_binds_the_fingerprint(): void
    {
        $fingerprint = hash('sha256', 'server-one');

        $this->activate()->assertOk();

        $license = $this->license->fresh();
        $this->assertSame(LicenseStatus::Active, $license->status);
        $this->assertSame($fingerprint, $license->fingerprint_hash);
        $this->assertNotNull($license->activated_at);
        $this->assertDatabaseHas('license_events', ['license_id' => $license->id, 'type' => 'activated']);
        $this->assertDatabaseHas('servers', ['fingerprint_hash' => $fingerprint, 'status' => 'online']);
    }

    public function test_unknown_key_returns_a_uniform_404(): void
    {
        $this->postJson('/api/v1/license/activate', [
            'key' => 'ATLS-0000-0000-0000-0000',
            'fingerprint' => hash('sha256', 'nope'),
        ])->assertStatus(404)->assertJsonPath('error.code', 'LICENSE_NOT_FOUND');
    }

    public function test_revoked_licence_cannot_activate(): void
    {
        $this->license->update(['status' => LicenseStatus::Revoked]);

        $this->activate()->assertStatus(403)->assertJsonPath('error.code', 'LICENSE_REVOKED');
        $this->assertDatabaseHas('security_events', ['type' => 'activation_blocked_revoked']);
    }

    public function test_expired_licence_cannot_activate(): void
    {
        $this->license->update(['status' => LicenseStatus::Active, 'expires_at' => now()->subDays(60)]);

        $this->activate()->assertStatus(410)->assertJsonPath('error.code', 'LICENSE_EXPIRED');
    }

    public function test_nonce_replay_is_rejected(): void
    {
        $nonce = (string) Str::uuid();

        $this->activate(['nonce' => $nonce])->assertOk();
        $this->activate(['nonce' => $nonce, 'fingerprint' => hash('sha256', 'server-two')])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'REPLAY_DETECTED');

        $this->assertDatabaseHas('security_events', ['type' => 'activation_replay']);
    }

    public function test_a_second_server_is_refused_when_the_activation_limit_is_reached(): void
    {
        $this->activate()->assertOk();

        $this->activate(['fingerprint' => hash('sha256', 'server-two')])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ACTIVATION_LIMIT_REACHED');
    }

    public function test_re_activating_the_same_server_is_idempotent(): void
    {
        $this->activate()->assertOk();
        $this->activate()->assertOk();

        $this->assertSame(1, $this->license->activations()->where('status', 'active')->count());
    }

    public function test_heartbeat_reports_read_only_after_revocation(): void
    {
        $this->activate()->assertOk();
        $this->license->update(['status' => LicenseStatus::Revoked]);

        $response = $this->postJson('/api/v1/license/heartbeat', [
            'key' => $this->license->key,
            'fingerprint' => hash('sha256', 'server-one'),
            'version' => '1.4.1',
        ])->assertOk();

        $this->assertTrue($response->json('read_only'));
        $this->assertSame('revoked', $response->json('status'));
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_heartbeat_from_an_unbound_server_is_refused(): void
    {
        $this->activate()->assertOk();

        $this->postJson('/api/v1/license/heartbeat', [
            'key' => $this->license->key,
            'fingerprint' => hash('sha256', 'attacker'),
        ])->assertStatus(404);
    }

    public function test_deactivation_unbinds_the_licence_for_reuse(): void
    {
        $this->activate()->assertOk();

        $this->postJson('/api/v1/license/deactivate', [
            'key' => $this->license->key,
            'fingerprint' => hash('sha256', 'server-one'),
        ])->assertOk();

        $this->assertNull($this->license->fresh()->fingerprint_hash);
        $this->assertDatabaseHas('license_activations', ['license_id' => $this->license->id, 'status' => 'deactivated']);

        // A different server can now take the licence.
        $this->activate(['fingerprint' => hash('sha256', 'server-two')])->assertOk();
    }

    public function test_licence_key_is_normalised_before_lookup(): void
    {
        $loose = strtolower(str_replace('-', ' ', $this->license->key));

        $this->activate(['key' => $loose])->assertOk();
    }

    public function test_tokens_are_signed_with_a_known_key_id(): void
    {
        $response = $this->activate()->assertOk();

        $kid = $response->json('kid');
        $this->assertTrue(LicenseKey::where('kid', $kid)->exists());
        $this->assertSame($kid, app(TokenService::class)->activeKey()->kid);
    }

    public function test_a_tampered_token_does_not_verify(): void
    {
        $token = $this->activate()->json('token');
        [$prefix, $payload, $signature] = explode('.', $token);

        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $claims['ent']['max_accounts'] = 9999;
        $forged = $prefix.'.'.rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=').'.'.$signature;

        $this->assertNull(app(TokenService::class)->verify($forged));
    }

    public function test_activation_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/license/activate', [
                'key' => 'ATLS-0000-0000-0000-0000',
                'fingerprint' => hash('sha256', 'flood-'.$i),
            ]);
        }

        $this->postJson('/api/v1/license/activate', [
            'key' => 'ATLS-0000-0000-0000-0000',
            'fingerprint' => hash('sha256', 'flood-final'),
        ])->assertStatus(429);
    }
}
