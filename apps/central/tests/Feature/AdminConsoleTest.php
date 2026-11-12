<?php

namespace Tests\Feature;

use App\Domain\Licensing\IssueLicense;
use App\Models\Customer;
use App\Models\License;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleAndPermissionSeeder::class, FeatureSeeder::class, PlanSeeder::class]);
    }

    private function staff(string $role = 'super_admin'): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole($role);

        Customer::create(['user_id' => $user->id, 'country' => 'IN']);

        return $user->fresh();
    }

    public function test_staff_console_pages_render(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Control plane');
        $this->actingAs($admin)->get('/admin/customers')->assertOk();
        $this->actingAs($admin)->get('/admin/licenses')->assertOk();
        $this->actingAs($admin)->get('/admin/plans')->assertOk()->assertSee('Professional');
        $this->actingAs($admin)->get('/admin/servers')->assertOk();
    }

    public function test_issuing_a_licence_creates_row_and_reveals_key_once(): void
    {
        $admin = $this->staff();
        $customer = Customer::first();
        $plan = Plan::where('slug', 'professional')->firstOrFail();

        $response = $this->actingAs($admin)->post('/admin/licenses', [
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'interval' => 'annual',
        ]);

        $license = License::firstOrFail();

        $response->assertRedirect("/admin/licenses/{$license->public_id}");
        $this->assertMatchesRegularExpression('/^ATLS(-[0-9A-HJKMNP-TV-Z]{4}){4}$/', $license->key);
        $this->assertEquals(now()->addYear()->startOfDay(), $license->expires_at->startOfDay());

        // Key is flashed to the session and shown on the next page only.
        $this->actingAs($admin)->get("/admin/licenses/{$license->public_id}")
            ->assertOk()
            ->assertSee($license->key);
    }

    public function test_licence_status_change_requires_a_reason(): void
    {
        $admin = $this->staff();
        $plan = Plan::where('slug', 'personal')->firstOrFail();

        $license = app(IssueLicense::class)
            ->handle(Customer::first(), $plan, 'monthly');

        $this->actingAs($admin)->patch("/admin/licenses/{$license->public_id}/status", [
            'status' => 'suspended',
        ])->assertSessionHasErrors('reason');

        $this->actingAs($admin)->patch("/admin/licenses/{$license->public_id}/status", [
            'status' => 'suspended',
            'reason' => 'Chargeback opened by card issuer',
        ])->assertRedirect();

        $this->assertSame('suspended', $license->fresh()->status->value);
    }

    public function test_plan_creation_writes_prices_and_entitlements(): void
    {
        $admin = $this->staff();

        $response = $this->actingAs($admin)->post('/admin/plans', [
            'name' => 'Starter',
            'slug' => 'starter',
            'tagline' => 'Test edition',
            'edition' => 'starter',
            'is_active' => '1',
            'is_featured' => '0',
            'sort_order' => 5,
            'price_inr' => '299.50',
            'price_usd' => '4',
            'entitlements' => [
                'max_accounts' => 2,
                'max_domains' => 10,
                'reseller_access' => 0,
                'app_installer' => 1,
            ],
        ]);

        $response->assertRedirect('/admin/plans');

        $plan = Plan::where('slug', 'starter')->firstOrFail();
        $this->assertSame(29950, $plan->prices()->where('currency', 'INR')->first()->amount_minor);
        $this->assertSame(400, $plan->prices()->where('currency', 'USD')->first()->amount_minor);
        $this->assertSame(2, $plan->entitlements()->where('feature_key', 'max_accounts')->first()->value);
        $this->assertSame(1, $plan->entitlements()->where('feature_key', 'app_installer')->first()->value);
    }

    public function test_plan_slug_must_be_unique(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)->post('/admin/plans', [
            'name' => 'Duplicate',
            'slug' => 'professional',
            'edition' => 'professional',
            'price_inr' => '1',
            'price_usd' => '1',
        ])->assertSessionHasErrors('slug');
    }

    public function test_entitlements_not_in_the_registry_are_ignored(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)->post('/admin/plans', [
            'name' => 'Rogue',
            'slug' => 'rogue',
            'edition' => 'rogue',
            'price_inr' => '1',
            'price_usd' => '1',
            'entitlements' => [
                'max_accounts' => 3,
                'evil_unlimited_everything' => 1,
            ],
        ])->assertRedirect();

        $plan = Plan::where('slug', 'rogue')->firstOrFail();

        $this->assertFalse($plan->entitlements()->where('feature_key', 'evil_unlimited_everything')->exists());
        $this->assertTrue($plan->entitlements()->where('feature_key', 'max_accounts')->exists());
    }

    public function test_support_role_can_view_but_not_manage_plans(): void
    {
        $support = $this->staff('support');

        $this->actingAs($support)->get('/admin/licenses')->assertOk();
        $this->actingAs($support)->get('/admin/plans')->assertForbidden();
        $this->actingAs($support)->post('/admin/plans', [
            'name' => 'X', 'slug' => 'x', 'edition' => 'x', 'price_inr' => 1, 'price_usd' => 1,
        ])->assertForbidden();
    }

    public function test_customer_search_filters_results(): void
    {
        $admin = $this->staff();

        $priya = User::factory()->create([
            'name' => 'Priya Nair',
            'email' => 'priya@example.com',
            'email_verified_at' => now(),
        ]);
        $priya->assignRole('customer');
        Customer::create(['user_id' => $priya->id, 'country' => 'IN']);

        $unrelated = User::factory()->create([
            'name' => 'Chidi Balogun',
            'email' => 'chidi@example.com',
            'email_verified_at' => now(),
        ]);
        $unrelated->assignRole('customer');
        Customer::create(['user_id' => $unrelated->id, 'country' => 'NG']);

        $this->actingAs($admin)->get('/admin/customers?q=Priya')
            ->assertOk()
            ->assertSee('Priya Nair')
            ->assertDontSee('Chidi Balogun');
    }
}
