<?php

namespace Tests\Feature;

use App\Domain\Billing\CheckoutService;
use App\Domain\Billing\DemoPaymentGateway;
use App\Domain\Billing\LedgerService;
use App\Domain\Billing\PaymentGateway;
use App\Domain\Billing\PaymentRecorder;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\License;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FakePaymentGateway;
use Tests\TestCase;

class BillingPortalTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    private User $customerUser;

    private User $otherUser;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleAndPermissionSeeder::class, FeatureSeeder::class, PlanSeeder::class, TaxRateSeeder::class]);

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        $this->customerUser = $this->makeCustomer('rohit@example.com', 'Rohit Kumar');
        $this->otherUser = $this->makeCustomer('meera@example.com', 'Meera Iyer');

        $this->admin = User::factory()->create(['email_verified_at' => now()]);
        $this->admin->assignRole('super_admin');
        Customer::create(['user_id' => $this->admin->id, 'country' => 'IN']);
        $this->admin = $this->admin->fresh();
    }

    private function makeCustomer(string $email, string $name): User
    {
        $user = User::factory()->create(['email' => $email, 'name' => $name, 'email_verified_at' => now()]);
        $user->assignRole('customer');

        Customer::create([
            'user_id' => $user->id,
            'company_name' => $name.' Studio',
            'country' => 'IN',
            'gstin' => '20ABCDE1234F1Z5',
            'billing_address' => ['state' => 'JH', 'city' => 'Dhanbad'],
        ]);

        return $user->fresh();
    }

    private function paidOrderFor(User $user): Order
    {
        $plan = Plan::with('prices')->where('slug', 'professional')->firstOrFail();

        $order = app(CheckoutService::class)->start($user->customer, $plan, 'monthly', 'INR');
        $this->gateway->stageCapturedPayment('pay_'.$order->id, $order->gateway_order_id, $order->total_minor);

        app(PaymentRecorder::class)->capture($order, $this->gateway->fetchPayment('pay_'.$order->id));

        return $order->fresh();
    }

    public function test_checkout_page_renders_with_server_side_totals(): void
    {
        $this->actingAs($this->customerUser)
            ->get('/billing/checkout?plan=professional&interval=annual')
            ->assertOk()
            ->assertSee('Review your order')
            ->assertSee('15,289.80'); // ₹1,499 × 12 less 15%
    }

    public function test_pay_page_renders_for_a_pending_order(): void
    {
        $plan = Plan::with('prices')->where('slug', 'professional')->firstOrFail();
        $order = app(CheckoutService::class)->start($this->customerUser->customer, $plan, 'monthly', 'INR');

        $this->actingAs($this->customerUser)
            ->get('/billing/pay/'.$order->public_id)
            ->assertOk()
            ->assertSee($order->number);
    }

    public function test_customer_cannot_pay_someone_elses_order(): void
    {
        $plan = Plan::with('prices')->where('slug', 'professional')->firstOrFail();
        $order = app(CheckoutService::class)->start($this->otherUser->customer, $plan, 'monthly', 'INR');

        $this->actingAs($this->customerUser)->get('/billing/pay/'.$order->public_id)->assertForbidden();
    }

    public function test_invoice_list_and_detail_render_for_the_owner(): void
    {
        $this->paidOrderFor($this->customerUser);
        $invoice = Invoice::firstOrFail();

        $this->actingAs($this->customerUser)->get('/billing/invoices')->assertOk()->assertSee($invoice->number);
        $this->actingAs($this->customerUser)->get('/billing/invoices/'.$invoice->public_id)->assertOk()
            ->assertSee($invoice->number)
            ->assertSee('SAC')
            ->assertSee('CGST');
    }

    public function test_customer_cannot_read_another_customers_invoice(): void
    {
        $this->paidOrderFor($this->customerUser);
        $invoice = Invoice::firstOrFail();

        $this->actingAs($this->otherUser)->get('/billing/invoices/'.$invoice->public_id)->assertForbidden();
    }

    public function test_subscriptions_page_lists_the_licence_period(): void
    {
        $this->paidOrderFor($this->customerUser);
        $license = License::firstOrFail();

        $this->actingAs($this->customerUser)->get('/billing/subscriptions')->assertOk()
            ->assertSee('Professional')
            ->assertSee($license->public_id);
    }

    public function test_customer_is_kept_out_of_the_staff_console(): void
    {
        $this->actingAs($this->customerUser)->get('/admin/orders')->assertForbidden();
        $this->actingAs($this->customerUser)->get('/admin/invoices')->assertForbidden();
        $this->actingAs($this->customerUser)->get('/admin/settings')->assertForbidden();
    }

    public function test_staff_console_billing_pages_render(): void
    {
        $this->paidOrderFor($this->customerUser);
        $invoice = Invoice::firstOrFail();

        foreach (['/admin/orders', '/admin/invoices', '/admin/subscriptions', '/admin/coupons'] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }

        $this->actingAs($this->admin)->get('/admin/invoices/'.$invoice->public_id)->assertOk()
            ->assertSee($invoice->number);
    }

    public function test_staff_console_operations_pages_render(): void
    {
        foreach (['/admin/tickets', '/admin/releases', '/admin/settings', '/admin/audit'] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
    }

    public function test_downloads_page_requires_a_licence_and_hides_links_without_one(): void
    {
        $this->actingAs($this->customerUser)->get('/downloads')->assertOk()->assertSee('An active licence is required');
    }

    public function test_customer_can_renew_an_existing_licence(): void
    {
        $this->paidOrderFor($this->customerUser);
        $license = License::firstOrFail();
        $subscription = $license->subscription;

        $this->actingAs($this->customerUser)
            ->post('/billing/renew/'.$license->public_id)
            ->assertRedirect();

        $renewal = Order::latest('id')->firstOrFail();

        $this->assertSame('renewal', $renewal->meta['type']);
        $this->assertSame($subscription->id, $renewal->meta['subscription_id']);
        $this->assertSame(1, License::count(), 'A renewal must extend the licence, not issue a second one.');
    }

    public function test_renewal_payment_extends_the_period_instead_of_duplicating_the_licence(): void
    {
        $this->paidOrderFor($this->customerUser);
        $license = License::firstOrFail();
        $originalEnd = $license->expires_at->copy();

        $subscription = $license->subscription;
        $plan = Plan::with('prices')->where('slug', 'professional')->firstOrFail();

        $renewal = app(CheckoutService::class)->start(
            $this->customerUser->customer, $plan, 'monthly', 'INR', null,
            ['type' => 'renewal', 'subscription_id' => $subscription->id, 'license_id' => $license->id],
        );

        $this->gateway->stageCapturedPayment('pay_renew', $renewal->gateway_order_id, $renewal->total_minor);
        app(PaymentRecorder::class)->capture($renewal, $this->gateway->fetchPayment('pay_renew'));

        $this->assertSame(1, License::count());
        $this->assertTrue($license->fresh()->expires_at->greaterThan($originalEnd));
        $this->assertSame($renewal->total_minor, $renewal->fresh()->total_minor);
        $this->assertSame('paid', $renewal->fresh()->status);
    }

    public function test_unverified_users_cannot_reach_billing(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $user->assignRole('customer');
        Customer::create(['user_id' => $user->id, 'country' => 'IN']);

        $this->actingAs($user)->get('/billing/invoices')->assertRedirect('/verify-email');
    }

    public function test_unknown_order_public_id_404s(): void
    {
        $this->actingAs($this->customerUser)->get('/billing/pay/'.Str::ulid())->assertNotFound();
    }

    public function test_demo_driver_captures_a_payment_through_the_real_pipeline(): void
    {
        config(['services.payments.driver' => 'demo']);
        $this->app->forgetInstance(PaymentGateway::class);

        $demo = new DemoPaymentGateway;
        $this->app->instance(PaymentGateway::class, $demo);

        $plan = Plan::with('prices')->where('slug', 'professional')->firstOrFail();
        $order = app(CheckoutService::class)->start($this->customerUser->customer, $plan, 'monthly', 'INR');

        $this->actingAs($this->customerUser)
            ->post('/billing/pay/'.$order->public_id.'/simulate')
            ->assertRedirect();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, License::count());
        $this->assertSame(0, app(LedgerService::class)->trialBalance('INR'));
    }

    public function test_demo_capture_route_is_unavailable_on_the_razorpay_driver(): void
    {
        $plan = Plan::with('prices')->where('slug', 'professional')->firstOrFail();
        $order = app(CheckoutService::class)->start($this->customerUser->customer, $plan, 'monthly', 'INR');

        $this->actingAs($this->customerUser)
            ->post('/billing/pay/'.$order->public_id.'/simulate')
            ->assertNotFound();

        $this->assertSame('pending', $order->fresh()->status);
    }
}
