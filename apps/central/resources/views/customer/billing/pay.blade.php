<x-app-layout title="Pay" :crumbs="[['label' => 'Billing', 'url' => route('billing.invoices')], ['label' => 'Payment']]">
    <div class="page-head">
        <div>
            <h1>Complete payment</h1>
            <p>Order {{ $order->number }} · {{ $order->items->first()->description }}</p>
        </div>
    </div>

    <div class="grid cols-2" style="gap:20px;align-items:start">
        <div class="card card-pad">
            <div class="eyebrow">Amount due</div>
            <div class="stat-value">{{ $order->currency === 'INR' ? '₹' : '$' }}{{ number_format($order->total_minor / 100, 2) }}</div>
            <p class="hint">
                Includes {{ $order->currency === 'INR' ? '₹' : '$' }}{{ number_format($order->tax_minor / 100, 2) }} tax
                @if ($order->discount_minor > 0) · after {{ number_format($order->discount_minor / 100, 2) }} discount @endif
            </p>

            <hr class="divider mt-2 mb-2">

            @if ($gatewayReady)
                <button id="rzp-button" class="btn btn-primary btn-block btn-lg">Pay securely with Razorpay</button>
                <p class="hint text-center mt-2">Card, UPI, netbanking and wallets. Card data never touches our servers.</p>
            @elseif ($demoMode)
                    <div class="panel-flat mt-2">
                    <div class="eyebrow mb-1">Demo payment driver</div>
                    <p class="small muted mb-2">
                        <code class="mono">ATLAS_PAYMENTS_DRIVER=demo</code> records a capture through the same
                        payment, invoicing, ledger and licensing path as a real one. Set the driver to
                        <code class="mono">razorpay</code> and add API keys to take real money.
                    </p>
                    <form method="POST" action="{{ route('billing.simulate', $order) }}">
                        @csrf
                        <button class="btn btn-secondary btn-sm" type="submit">Capture demo payment</button>
                    </form>
                </div>
            @else
                <x-alert variant="warn" title="Payment gateway not configured">
                    Set <code class="mono">RAZORPAY_KEY_ID</code>, <code class="mono">RAZORPAY_KEY_SECRET</code> and
                    <code class="mono">RAZORPAY_WEBHOOK_SECRET</code> to enable live checkout.
                </x-alert>
            @endif
        </div>

        <div class="card">
            <div class="card-head"><h2>What happens next</h2></div>
            <div class="card-body stack small" style="--stack:12px;color:var(--ink-2)">
                <p><strong>1.</strong> The payment is verified on our server with your provider signature.</p>
                <p><strong>2.</strong> A GST invoice is generated and the licence is issued to your account.</p>
                <p><strong>3.</strong> You install the panel on your VPS with the licence key.</p>
                <p class="muted">If the provider reports a different amount than we calculated, the payment is refused and flagged. We never grant a licence on the strength of a browser callback alone.</p>
            </div>
        </div>
    </div>

    @if ($gatewayReady)
        <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
        <script>
            const options = {
                key: @json($publicKey),
                amount: {{ $order->total_minor }},
                currency: @json($order->currency),
                name: @json(config('app.name')),
                description: @json($order->items->first()->description),
                order_id: @json($order->gateway_order_id),
                prefill: { email: @json(auth()->user()->email), name: @json(auth()->user()->name) },
                theme: { color: '#12886a' },
                handler: function (response) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = @json(route('billing.verify', $order));
                    const fields = {
                        _token: @json(csrf_token()),
                        razorpay_order_id: response.razorpay_order_id,
                        razorpay_payment_id: response.razorpay_payment_id,
                        razorpay_signature: response.razorpay_signature,
                    };
                    for (const [k, v] of Object.entries(fields)) {
                        const input = document.createElement('input');
                        input.type = 'hidden'; input.name = k; input.value = v;
                        form.appendChild(input);
                    }
                    document.body.appendChild(form);
                    form.submit();
                },
            };
            document.getElementById('rzp-button').addEventListener('click', () => new Razorpay(options).open());
        </script>
    @endif
</x-app-layout>
