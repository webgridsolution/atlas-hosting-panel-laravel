<?php

namespace Tests\Feature;

use App\Domain\Licensing\IssueLicense;
use App\Enums\LicenseStatus;
use App\Models\Customer;
use App\Models\License;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleAndPermissionSeeder::class, FeatureSeeder::class, PlanSeeder::class]);
    }

    private function customerUser(string $email = 'owner@example.com'): User
    {
        $user = User::factory()->create(['email' => $email, 'email_verified_at' => now()]);
        $user->assignRole('customer');

        Customer::create(['user_id' => $user->id, 'country' => 'IN']);

        return $user->fresh();
    }

    private function licenseFor(User $user, string $interval = 'annual'): License
    {
        return app(IssueLicense::class)->handle(
            $user->customer,
            Plan::where('slug', 'professional')->firstOrFail(),
            $interval,
        );
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/licenses')->assertRedirect('/login');
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_unverified_users_are_held_at_verification(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $user->assignRole('customer');
        Customer::create(['user_id' => $user->id, 'country' => 'IN']);

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/verify-email');
    }

    public function test_customer_sees_their_own_licence(): void
    {
        $user = $this->customerUser();
        $license = $this->licenseFor($user);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee($license->maskedKey())
            ->assertDontSee($license->key); // full key never rendered by default
    }

    public function test_customer_cannot_view_another_customers_licence(): void
    {
        $alice = $this->customerUser('alice@example.com');
        $bob = $this->customerUser('bob@example.com');

        $bobLicense = $this->licenseFor($bob);

        $this->actingAs($alice)
            ->get("/licenses/{$bobLicense->public_id}")
            ->assertForbidden();
    }

    public function test_customer_cannot_reach_the_staff_console(): void
    {
        $user = $this->customerUser();

        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->actingAs($user)->get('/admin/licenses')->assertForbidden();
    }

    public function test_customer_can_register_a_server_and_only_against_own_licence(): void
    {
        $alice = $this->customerUser('alice@example.com');
        $bob = $this->customerUser('bob@example.com');
        $bobLicense = $this->licenseFor($bob);

        $this->actingAs($alice)->post('/servers', [
            'hostname' => 'panel.alice.dev',
            'os' => 'ubuntu-24.04',
        ])->assertRedirect('/servers');

        $this->assertDatabaseHas('servers', [
            'hostname' => 'panel.alice.dev',
            'status' => 'pending',
        ]);

        // Attempting to bind someone else's licence must be refused.
        $this->actingAs($alice)->post('/servers', [
            'hostname' => 'evil.alice.dev',
            'license_id' => $bobLicense->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('servers', ['hostname' => 'evil.alice.dev']);
    }

    public function test_server_hostname_is_validated(): void
    {
        $user = $this->customerUser();

        $this->actingAs($user)->post('/servers', ['hostname' => 'not a hostname'])
            ->assertSessionHasErrors('hostname');
    }

    public function test_customer_can_decommission_own_server_only(): void
    {
        $alice = $this->customerUser('alice@example.com');
        $bob = $this->customerUser('bob@example.com');

        $server = Server::create([
            'customer_id' => $bob->customer->id,
            'hostname' => 'bob.example.com',
            'status' => 'online',
        ]);

        $this->actingAs($alice)->delete("/servers/{$server->public_id}")->assertForbidden();

        $this->actingAs($bob)->delete("/servers/{$server->public_id}")->assertRedirect('/servers');
        $this->assertSame('decommissioned', $server->fresh()->status);
    }

    public function test_expired_licence_still_renders_portal_and_reports_state(): void
    {
        $user = $this->customerUser();
        $license = $this->licenseFor($user, 'monthly');

        $license->update([
            'status' => LicenseStatus::Active,
            'expires_at' => now()->subDays(60),
        ]);

        $this->assertSame(LicenseStatus::Expired, $license->fresh()->effectiveStatus());

        $this->actingAs($user)->get("/licenses/{$license->public_id}")
            ->assertOk()
            ->assertSee('Expired');
    }
}
