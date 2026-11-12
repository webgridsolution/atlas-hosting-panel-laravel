<?php

namespace Tests\Feature;

use App\Domain\Billing\CheckoutService;
use App\Domain\Billing\GstCalculator;
use App\Domain\Billing\PaymentGateway;
use App\Domain\Billing\Pricing;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakePaymentGateway;
use Tests\TestCase;

class CommerceTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    private User $customerUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleAndPermissionSeeder::class, FeatureSeeder::class, PlanSeeder::class]);

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        $this->customerUser = User::factory()->create(['email_verified_at' => now()]);
        $this->customerUser->assignRole('customer');

        Customer::create([
            'user_id' => $this->customerUser->id,
            'country' => 'IN',
            'billing_address' => ['state' => 'JH', 'city' => 'Dhanbad'],
        ]);
    }

    private function plan(): Plan
    {
        return Plan::with('prices')->where('slug', 'professional')->firstOrFail();
    }

    public function test_annual_price_is_derived_from_the_monthly_rate_with_the_period_discount(): void
    {
        $priced = app(Pricing::class)->price($this->plan(), 'annual', 'INR');

        // ₹1,499/month = 149900 minor units. 149900 * 12 = 1,798,800; less 15% = 1,528,980.
        $this->assertSame(149900 * 12, 1798800);
        $this->assertSame(1798800 - (int) round(1798800 * 0.15), $priced['subtotal']);
        $this->assertSame(1528980, $priced['subtotal']);
        $this->assertSame($priced['subtotal'], $priced['taxable']);
    }

    public function test_checkout_ignores_client_supplied_amounts(): void
    {
        $response = $this->actingAs($this->customerUser)->post('/billing/checkout', [
            'plan' => 'professional',
            'interval' => 'monthly',
            'currency' => 'INR',
            'total_minor' => 1,
            'amount' => 0.01,
        ]);

        $order = Order::with('items')->latest('id')->firstOrFail();

        $this->assertSame(149900, $order->subtotal_minor);
        $this->assertSame(149900, $order->items->first()->line_total_minor);
        $this->assertSame($order->total_minor, $this->gateway->createdOrders[0]['amountMinor']);
        $response->assertRedirect('/billing/pay/'.$order->public_id);
    }

    public function test_gst_is_split_between_cgst_and_sgst_for_an_intra_state_sale(): void
    {
        $this->actingAs($this->customerUser)->post('/billing/checkout', [
            'plan' => 'professional', 'interval' => 'monthly', 'currency' => 'INR',
        ]);

        $order = Order::latest('id')->firstOrFail();

        $this->assertGreaterThan(0, $order->meta['tax']['cgst']);
        $this->assertSame($order->meta['tax']['cgst'], $order->meta['tax']['sgst']);
        $this->assertSame(0, $order->meta['tax']['igst']);
        // 18% of 149900 = 26982
        $this->assertSame(26982, $order->tax_minor);
        $this->assertSame(149900 + 26982, $order->total_minor);
    }

    public function test_inter_state_sale_uses_igst(): void
    {
        $this->customerUser->customer->update(['billing_address' => ['state' => 'KA']]);

        $this->actingAs($this->customerUser->fresh())->post('/billing/checkout', [
            'plan' => 'professional', 'interval' => 'monthly', 'currency' => 'INR',
        ]);

        $tax = Order::latest('id')->firstOrFail()->meta['tax'];

        $this->assertSame(26982, $tax['igst']);
        $this->assertSame(0, $tax['cgst']);
    }

    public function test_export_sale_is_zero_rated(): void
    {
        $this->customerUser->customer->update(['country' => 'US', 'billing_address' => ['state' => 'CA']]);

        $tax = app(GstCalculator::class)->calculate(100000, 'US', 'CA');

        $this->assertTrue($tax['is_export']);
        $this->assertSame(0, $tax['total_tax']);
    }

    public function test_percentage_coupon_reduces_the_taxable_amount(): void
    {
        Coupon::create([
            'code' => 'LAUNCH10', 'kind' => 'percent', 'value' => 1000,
            'max_redemptions' => 10, 'is_active' => true,
        ]);

        $this->actingAs($this->customerUser)->post('/billing/checkout', [
            'plan' => 'professional', 'interval' => 'monthly', 'currency' => 'INR', 'coupon' => 'launch10',
        ]);

        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(14990, $order->discount_minor);
        $this->assertSame(149900 - 14990, $order->subtotal_minor - $order->discount_minor);
        $this->assertSame(1, Coupon::first()->redeemed_count);
    }

    public function test_expired_coupon_is_refused_uniformly(): void
    {
        Coupon::create([
            'code' => 'OLD', 'kind' => 'percent', 'value' => 1000,
            'ends_at' => now()->subDay(), 'is_active' => true,
        ]);

        $this->actingAs($this->customerUser)
            ->post('/billing/checkout', [
                'plan' => 'professional', 'interval' => 'monthly', 'currency' => 'INR', 'coupon' => 'OLD',
            ])
            ->assertSessionHasErrors('coupon');

        $this->assertSame(0, Order::count());
    }

    public function test_fixed_coupon_cannot_exceed_the_subtotal(): void
    {
        $coupon = Coupon::create([
            'code' => 'BIG', 'kind' => 'fixed', 'value' => 99999999, 'currency' => 'INR', 'is_active' => true,
        ]);

        $this->assertSame(149900, $coupon->discountFor(149900, 'INR'));
    }

    public function test_checkout_requires_authentication(): void
    {
        $this->get('/billing/checkout?plan=professional')->assertRedirect('/login');
    }

    public function test_inactive_plan_cannot_be_purchased(): void
    {
        Plan::where('slug', 'professional')->update(['is_active' => false]);

        $this->expectException(ValidationException::class);

        app(CheckoutService::class)->start(
            $this->customerUser->customer,
            $this->plan(),
            'monthly',
            'INR',
        );
    }
}
