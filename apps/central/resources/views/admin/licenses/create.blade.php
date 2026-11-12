<x-app-layout title="Issue licence" :crumbs="[['label' => 'Licences', 'url' => route('admin.licenses.index')], ['label' => 'Issue']]">
    <div class="page-head">
        <div>
            <h1>Issue a licence</h1>
            <p>Manual issuance for support, trials and pre-billing sales. The Razorpay flow will call the same service in Phase 3.</p>
        </div>
    </div>

    <div class="grid cols-2" style="gap:16px;align-items:start">
        <div class="card">
            <div class="card-head"><h2>Licence details</h2></div>
            <form method="POST" action="{{ route('admin.licenses.store') }}" class="card-body">
                @csrf

                <x-select label="Customer" name="customer_id" required
                          :options="$customers->mapWithKeys(fn ($c) => [$c->id => $c->user->name.' · '.$c->user->email])->all()"
                          placeholder="Select a customer" />

                <div class="mt-2">
                    <x-select label="Plan" name="plan_id" required
                              :options="$plans->mapWithKeys(fn ($p) => [$p->id => $p->name.' — '.($p->priceFor('monthly', 'INR')?->formatted() ?? 'no price').'/mo'])->all()"
                              placeholder="Select a plan" />
                </div>

                <div class="mt-2">
                    <x-select label="Billing interval" name="interval" :selected="old('interval', 'monthly')" :options="[
                        'monthly' => 'Monthly',
                        'quarterly' => 'Quarterly',
                        'half_yearly' => 'Half-yearly',
                        'annual' => 'Annual',
                        'lifetime' => 'Lifetime (no expiry)',
                    ]" hint="Sets the expiry date. Grace period defaults to 14 days." />
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Issue licence</button>
                    <a href="{{ route('admin.licenses.index') }}" class="btn btn-ghost">Cancel</a>
                </div>
            </form>
        </div>

        <div class="stack" style="--stack:14px">
            <x-alert variant="info" title="The key is shown once">
                After issuing, deliver the key to the customer through a secure channel. It is masked everywhere else in the console.
            </x-alert>

            <div class="card card-pad">
                <div class="eyebrow mb-1">What issuance does</div>
                <ul class="stack small" style="--stack:8px;color:var(--ink-2)">
                    <li>Creates a licence in <code class="mono">pending_activation</code>.</li>
                    <li>Generates an 80-bit Crockford Base32 key (<code class="mono">ATLS-…</code>).</li>
                    <li>Snapshots nothing yet — entitlements are read from the plan at activation.</li>
                    <li>Writes no payment records: this is not a sale until an invoice exists.</li>
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
