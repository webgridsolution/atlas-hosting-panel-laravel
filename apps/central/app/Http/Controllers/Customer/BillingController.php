<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Billing\CheckoutService;
use App\Domain\Billing\DemoPaymentGateway;
use App\Domain\Billing\GstCalculator;
use App\Domain\Billing\PaymentGateway;
use App\Domain\Billing\PaymentRecorder;
use App\Domain\Billing\Pricing;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\License;
use App\Models\Order;
use App\Models\Plan;
use App\Models\SecurityEvent;
use App\Models\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly PaymentGateway $gateway,
        private readonly PaymentRecorder $recorder,
    ) {}

    /** Order summary before handing over to the gateway. */
    public function create(Request $request): View|RedirectResponse
    {
        $plan = Plan::active()->with(['prices', 'entitlements'])->where('slug', $request->query('plan'))->first();

        // A checkout URL without a valid plan is usually a stale bookmark or a
        // plan that was retired. Sending the customer to the price list, where
        // every purchasable plan is listed, beats a bare 404 on the one page in
        // the purchase flow that has to work.
        if (! $plan) {
            return redirect()->route('pricing')
                ->with('status', 'Choose a plan to continue to checkout.');
        }

        $interval = (string) $request->query('interval', 'monthly');
        $currency = strtoupper((string) $request->query('currency', 'INR'));

        $customer = $request->user()->customer;
        $priced = app(Pricing::class)->price($plan, $interval, $currency, null);
        $tax = app(GstCalculator::class)->calculate(
            $priced['taxable'], $customer->country, $customer->billing_address['state'] ?? null
        );

        return view('customer.billing.checkout', [
            'plan' => $plan,
            'interval' => $interval,
            'currency' => $currency,
            'priced' => $priced,
            'tax' => $tax,
            'total' => $priced['taxable'] + $tax['total_tax'],
            'intervals' => array_keys(Pricing::INTERVAL_MONTHS),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'exists:plans,slug'],
            'interval' => ['required', 'in:monthly,quarterly,half_yearly,annual'],
            'currency' => ['required', 'in:INR,USD'],
            'coupon' => ['nullable', 'string', 'max:40'],
        ]);

        $plan = Plan::where('slug', $data['plan'])->firstOrFail();

        $order = $this->checkout->start(
            $request->user()->customer,
            $plan,
            $data['interval'],
            $data['currency'],
            $data['coupon'] ?? null,
        );

        return redirect()->route('billing.pay', $order);
    }

    /** Renewal: an order that extends an existing licence instead of issuing a new one. */
    public function renew(Request $request, License $license): RedirectResponse
    {
        abort_unless($license->customer_id === $request->user()->customer->id, 403);

        $subscription = Subscription::where('license_id', $license->id)->latest()->first();

        if (! $subscription) {
            return back()->withErrors(['renew' => 'This licence was not purchased through a subscription; contact support to renew.']);
        }

        $order = $this->checkout->start(
            $request->user()->customer,
            $subscription->plan,
            $subscription->interval,
            $subscription->currency,
            null,
            ['type' => 'renewal', 'subscription_id' => $subscription->id, 'license_id' => $license->id],
        );

        return redirect()->route('billing.pay', $order);
    }

    public function pay(Request $request, Order $order): View|RedirectResponse
    {
        abort_unless($order->customer_id === $request->user()->customer->id, 403);

        if ($order->status === 'paid') {
            return redirect()->route('billing.invoices');
        }

        abort_unless($order->isPayable(), 400);

        return view('customer.billing.pay', [
            'order' => $order->load('items'),
            'publicKey' => $this->gateway->publicKey(),
            'gatewayReady' => filled(config('services.razorpay.key_id')) && filled(config('services.razorpay.key_secret')),
            'demoMode' => $this->gateway instanceof DemoPaymentGateway,
        ]);
    }

    /** Browser callback. Grants nothing on a bare success response: signature + provider fetch required. */
    public function verify(Request $request, Order $order): RedirectResponse
    {
        abort_unless($order->customer_id === $request->user()->customer->id, 403);

        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        if (! hash_equals((string) $order->gateway_order_id, $data['razorpay_order_id'])) {
            abort(400, 'Order mismatch.');
        }

        if (! $this->gateway->verifyPaymentSignature($data['razorpay_order_id'], $data['razorpay_payment_id'], $data['razorpay_signature'])) {
            SecurityEvent::raise('checkout_signature_invalid', 'high', ['order' => $order->number]);

            abort(400, 'Payment signature could not be verified.');
        }

        // Authoritative amount/status come from the provider, not from the browser.
        $providerPayment = $this->gateway->fetchPayment($data['razorpay_payment_id']);
        $invoice = $this->recorder->capture($order, $providerPayment);

        return redirect()->route('billing.invoice', $invoice)->with('status', "Payment received — invoice {$invoice->number} is ready.");
    }

    /**
     * Demo/driver-gated capture. Refuses to run on the razorpay driver, so it can
     * never be used to obtain a licence for free in production.
     */
    public function simulate(Request $request, Order $order): RedirectResponse
    {
        abort_unless($this->gateway instanceof DemoPaymentGateway, 404);
        abort_unless($order->customer_id === $request->user()->customer->id, 403);
        abort_unless($order->isPayable(), 400);

        $providerPayment = $this->gateway->simulateCapture((string) $order->gateway_order_id);
        $invoice = $this->recorder->capture($order, $providerPayment);

        return redirect()->route('billing.invoice', $invoice)
            ->with('status', "Demo payment captured — invoice {$invoice->number} is ready.");
    }

    public function invoices(Request $request): View
    {
        return view('customer.billing.invoices', [
            'invoices' => Invoice::where('customer_id', $request->user()->customer->id)
                ->latest('issued_at')->paginate(15),
            'orders' => Order::where('customer_id', $request->user()->customer->id)
                ->whereIn('status', ['pending', 'failed'])->latest()->limit(5)->get(),
        ]);
    }

    public function invoice(Request $request, Invoice $invoice): View
    {
        abort_unless($invoice->customer_id === $request->user()->customer->id, 403);

        return view('customer.billing.invoice', ['invoice' => $invoice->load('items', 'payment', 'customer.user')]);
    }

    public function subscriptions(Request $request): View
    {
        return view('customer.billing.subscriptions', [
            'subscriptions' => Subscription::where('customer_id', $request->user()->customer->id)
                ->with(['plan', 'license'])->latest()->paginate(15),
        ]);
    }
}
