<?php

namespace Tests\Feature;

use App\Domain\Billing\CheckoutService;
use App\Domain\Billing\LedgerService;
use App\Domain\Billing\PaymentGateway;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\License;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Refund;
use App\Models\User;
use App\Models\WebhookEvent;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePaymentGateway;
use Tests\TestCase;

class RazorpayWebhookTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    private User $customerUser;

    private Order $order;

    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleAndPermissionSeeder::class, FeatureSeeder::class, PlanSeeder::class]);
        config(['services.razorpay.webhook_secret' => self::SECRET]);

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        $this->customerUser = User::factory()->create(['email_verified_at' => now()]);
        $this->customerUser->assignRole('customer');
        Customer::create([
            'user_id' => $this->customerUser->id,
            'country' => 'IN',
            'billing_address' => ['state' => 'JH'],
        ]);

        $plan = Plan::with('prices')->where('slug', 'professional')->firstOrFail();

        $this->order = app(CheckoutService::class)
            ->start($this->customerUser->customer, $plan, 'monthly', 'INR');
    }

    private function body(string $eventId, string $event, string $paymentId, int $amount, string $status = 'captured'): string
    {
        return json_encode([
            'id' => $eventId,
            'event' => $event,
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => $paymentId,
                        'order_id' => $this->order->gateway_order_id,
                        'amount' => $amount,
                        'status' => $status,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function postWebhook(string $body, ?string $signature = null)
    {
        return $this->call(
            'POST',
            '/webhooks/razorpay',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-RAZORPAY-SIGNATURE' => $signature ?? hash_hmac('sha256', $body, self::SECRET),
                'HTTP_X-RAZORPAY-EVENT-ID' => 'evt_header',
            ],
            $body,
        );
    }

    public function test_signature_is_verified_before_the_body_is_parsed(): void
    {
        $response = $this->postWebhook('{"id":"evt_1","event":"payment.captured"}', 'deadbeef');

        $response->assertStatus(400);
        $this->assertSame(0, Payment::count());
        $this->assertDatabaseHas('security_events', ['type' => 'webhook_signature_invalid']);
    }

    public function test_missing_signature_is_rejected(): void
    {
        $this->postWebhook('{}', '')->assertStatus(400);
    }

    public function test_captured_payment_creates_payment_invoice_ledger_and_licence(): void
    {
        $amount = $this->order->total_minor;
        $this->gateway->stageCapturedPayment('pay_1', $this->order->gateway_order_id, $amount);

        $this->postWebhook($this->body('evt_cap_1', 'payment.captured', 'pay_1', $amount))
            ->assertOk()
            ->assertJson(['status' => 'processed']);

        $this->assertSame(1, Payment::count());
        $this->assertSame('paid', $this->order->fresh()->status);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, License::count());
        $this->assertSame(0, app(LedgerService::class)->trialBalance('INR'));

        $invoice = Invoice::with('items')->firstOrFail();
        $this->assertMatchesRegularExpression('#^INV/\d{4}-\d{2}/\d{4}$#', $invoice->number);
        $this->assertSame($this->order->total_minor, $invoice->total_minor);
        $this->assertSame('997331', $invoice->items->first()->sac_code);
    }

    public function test_replayed_webhook_is_idempotent(): void
    {
        $amount = $this->order->total_minor;
        $this->gateway->stageCapturedPayment('pay_dup', $this->order->gateway_order_id, $amount);
        $body = $this->body('evt_dup', 'payment.captured', 'pay_dup', $amount);

        $this->postWebhook($body)->assertOk();
        $this->postWebhook($body)->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, Payment::count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, License::count());
        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_two_different_event_ids_for_the_same_payment_do_not_double_charge(): void
    {
        $amount = $this->order->total_minor;
        $this->gateway->stageCapturedPayment('pay_once', $this->order->gateway_order_id, $amount);

        $this->postWebhook($this->body('evt_a', 'payment.captured', 'pay_once', $amount));
        $this->postWebhook($this->body('evt_b', 'order.paid', 'pay_once', $amount));

        $this->assertSame(1, Payment::count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, License::count());
    }

    public function test_amount_mismatch_grants_nothing_and_raises_a_security_event(): void
    {
        // Provider says a different amount than we calculated.
        $this->gateway->stageCapturedPayment('pay_bad', $this->order->gateway_order_id, 100);

        $this->postWebhook($this->body('evt_bad', 'payment.captured', 'pay_bad', 100))
            ->assertStatus(500);

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, License::count());
        $this->assertSame('pending', $this->order->fresh()->status);
        $this->assertDatabaseHas('security_events', ['type' => 'payment_amount_mismatch', 'severity' => 'critical']);
    }

    public function test_uncaptured_payment_grants_nothing(): void
    {
        $amount = $this->order->total_minor;
        $this->gateway->stageCapturedPayment('pay_auth', $this->order->gateway_order_id, $amount, ['status' => 'authorized']);

        $this->postWebhook($this->body('evt_auth', 'payment.captured', 'pay_auth', $amount, 'authorized'))
            ->assertStatus(500);

        $this->assertSame(0, Payment::count());
    }

    public function test_payment_failed_marks_the_order_failed(): void
    {
        $this->postWebhook($this->body('evt_fail', 'payment.failed', 'pay_fail', 100, 'failed'))->assertOk();

        $this->assertSame('failed', $this->order->fresh()->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_unknown_event_type_is_ignored_not_errored(): void
    {
        $this->postWebhook($this->body('evt_other', 'settlement.created', 'pay_x', 1))
            ->assertOk()
            ->assertJson(['status' => 'ignored']);
    }

    public function test_browser_callback_with_a_forged_signature_is_refused(): void
    {
        $this->gateway->stageCapturedPayment('pay_forged', $this->order->gateway_order_id, $this->order->total_minor);

        $this->actingAs($this->customerUser)
            ->post("/billing/pay/{$this->order->public_id}/verify", [
                'razorpay_order_id' => $this->order->gateway_order_id,
                'razorpay_payment_id' => 'pay_forged',
                'razorpay_signature' => 'not-valid',
            ])
            ->assertStatus(400);

        $this->assertSame(0, Payment::count());
        $this->assertDatabaseHas('security_events', ['type' => 'checkout_signature_invalid']);
    }

    public function test_browser_callback_rejects_a_mismatched_order(): void
    {
        $this->actingAs($this->customerUser)
            ->post("/billing/pay/{$this->order->public_id}/verify", [
                'razorpay_order_id' => 'order_SOMEONE_ELSE',
                'razorpay_payment_id' => 'pay_x',
                'razorpay_signature' => 'valid-signature',
            ])
            ->assertStatus(400);
    }

    public function test_browser_callback_still_re_fetches_the_payment_before_granting(): void
    {
        // Signature passes, but the provider reports a different amount.
        $this->gateway->stageCapturedPayment('pay_short', $this->order->gateway_order_id, 5000);

        $this->actingAs($this->customerUser)
            ->post("/billing/pay/{$this->order->public_id}/verify", [
                'razorpay_order_id' => $this->order->gateway_order_id,
                'razorpay_payment_id' => 'pay_short',
                'razorpay_signature' => 'valid-signature',
            ]);

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, License::count());
    }

    public function test_refund_webhook_marks_the_invoice_refunded_and_balances_the_ledger(): void
    {
        $amount = $this->order->total_minor;
        $this->gateway->stageCapturedPayment('pay_ref', $this->order->gateway_order_id, $amount);
        $this->postWebhook($this->body('evt_pay', 'payment.captured', 'pay_ref', $amount));

        $refundBody = json_encode([
            'id' => 'evt_refund',
            'event' => 'refund.processed',
            'payload' => ['refund' => ['entity' => [
                'id' => 'rfnd_1', 'payment_id' => 'pay_ref', 'amount' => $amount, 'status' => 'processed',
            ]]],
        ], JSON_THROW_ON_ERROR);

        $this->postWebhook($refundBody)->assertOk();

        $this->assertSame('refunded', Invoice::firstOrFail()->status);
        $this->assertDatabaseHas('refunds', ['gateway_refund_id' => 'rfnd_1', 'amount_minor' => $amount]);
        $this->assertSame(0, app(LedgerService::class)->trialBalance('INR'));

        // Replaying the refund must not post twice.
        $this->postWebhook($refundBody)->assertOk();
        $this->assertSame(1, Refund::count());
    }
}
