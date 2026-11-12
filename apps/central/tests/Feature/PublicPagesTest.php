<?php

namespace Tests\Feature;

use App\Models\Plan;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FeatureSeeder::class, PlanSeeder::class]);
    }

    public function test_home_page_renders_the_offer(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('control panel you install on your own server')
            ->assertSee('How it works')
            ->assertSee('Professional');
    }

    public function test_pricing_page_renders_plan_catalogue_from_database(): void
    {
        $this->get('/pricing')
            ->assertOk()
            ->assertSee('Professional')
            ->assertSee('₹1,499')
            ->assertSee('$18')
            ->assertSee('Hosting accounts');
    }

    public function test_pricing_page_does_not_hardcode_prices(): void
    {
        // Change the database price; the page must follow without a deploy.
        Plan::where('slug', 'professional')->first()
            ->prices()->where('currency', 'INR')->update(['amount_minor' => 99900]);

        $this->get('/pricing')
            ->assertOk()
            ->assertSee('₹999')
            ->assertDontSee('₹1,499');
    }

    public function test_documentation_and_legal_pages_render(): void
    {
        $this->get('/docs')->assertOk()->assertSee('Installation');
        $this->get('/legal')->assertOk()->assertSee('Refunds and cancellation');
    }

    public function test_guest_can_open_auth_screens(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');
        $this->get('/register')->assertOk()->assertSee('Create your account');
        $this->get('/forgot-password')->assertOk()->assertSee('Reset your password');
    }

    public function test_health_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }
}
