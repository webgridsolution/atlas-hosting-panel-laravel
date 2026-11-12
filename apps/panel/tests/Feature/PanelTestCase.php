<?php

namespace Tests\Feature;

use App\Domain\Agent\Agent;
use App\Domain\Security\Totp;
use App\Models\Account;
use App\Models\Licence;
use App\Models\Package;
use App\Models\User;
use Database\Seeders\PackageSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeAgent;
use Tests\TestCase;

abstract class PanelTestCase extends TestCase
{
    use RefreshDatabase;

    protected FakeAgent $agent;

    /** A sodium keypair, kept so repeated mints share one public key. */
    private $testKeyPair = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The panel must never talk to a real socket in tests.
        $this->agent = new FakeAgent;
        $this->app->instance(Agent::class, $this->agent);

        $this->seed([RoleSeeder::class, PackageSeeder::class]);

        // Every realistic install has a licence; tests start from that state and
        // the licence-specific tests tear it down explicitly.
        $this->activateLicence();
    }

    /**
     * Stores a licence row whose token is genuinely signed by a throwaway
     * keypair, so the verification path under test is the real one.
     */
    protected function activateLicence(array $claims = [], ?int $expiresIn = null): string
    {
        $token = $this->mintToken($claims, $expiresIn);

        Licence::create([
            'token' => $token,
            'kid' => 'test-kid',
            'state' => 'active',
            'last_checked_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        cache()->forget('licence:evaluate');

        return $token;
    }

    /**
     * Signs a token with a fresh keypair and installs the matching public key
     * into config, without touching the database — what the vendor would send.
     *
     * Claims are vendor-shaped: `ent` carries entitlements, `sts` the status.
     */
    protected function mintToken(array $claims = [], ?int $expiresIn = null): string
    {
        $this->testKeyPair = sodium_crypto_sign_keypair();

        $secret = sodium_crypto_sign_secretkey($this->testKeyPair);
        $public = sodium_crypto_sign_publickey($this->testKeyPair);

        config(['atlas.central.keys' => ['test-kid' => $this->b64url($public)]]);

        $payload = array_merge([
            'kid' => 'test-kid',
            'lic' => 'LIC-TEST',
            'ent' => ['mail' => true, 'dns' => true, 'backups' => true],
            'sts' => 'active',
            'iat' => time(),
            'nbf' => time() - 60,
            'exp' => $expiresIn ?? time() + 7 * 86400,
        ], $claims);

        $body = $this->b64url(json_encode($payload));

        return 'atlas-lic-v1.'.$body.'.'.$this->b64url(
            sodium_crypto_sign_detached('atlas-lic-v1.'.$body, $secret)
        );
    }

    protected function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    protected function user(string $role, array $attributes = []): User
    {
        $user = User::create(array_merge([
            'name' => ucfirst($role).' User',
            'email' => $role.'-'.uniqid().'@atlas.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ], $attributes));

        $user->assignRole($role);

        return $user;
    }

    /**
     * A customer whose second factor is already live, with a secret the test
     * knows so it can compute valid codes. Recovery codes are passed in plain and
     * stored the way the controller stores them: as SHA-256 hashes.
     *
     * @param  array<int, string>  $recoveryCodes
     */
    protected function twoFactorUser(array $recoveryCodes = [], string $role = 'customer'): User
    {
        $user = $this->user($role);

        $user->forceFill([
            'two_factor_secret' => Totp::generateSecret(),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $recoveryCodes),
        ])->save();

        return $user->fresh();
    }

    protected function package(): Package
    {
        return Package::where('name', 'Business')->firstOrFail();
    }

    /**
     * An account that already exists in the database, without going through
     * provisioning, so a test can focus on the screen it is exercising.
     */
    protected function account(?User $owner = null, ?User $reseller = null, array $attributes = []): Account
    {
        $package = $this->package();

        return Account::create(array_merge([
            'user_id' => $owner?->id,
            'reseller_id' => $reseller?->id,
            'package_id' => $package->id,
            'username' => 'acct'.substr(uniqid(), -6),
            'domain' => 'site-'.substr(uniqid(), -6).'.test',
            'status' => Account::STATUS_ACTIVE,
            'disk_mb' => $package->disk_mb,
            'inodes' => $package->inodes,
            'bandwidth_mb' => $package->bandwidth_mb,
            'php_version' => $package->php_version,
        ], $attributes));
    }
}
