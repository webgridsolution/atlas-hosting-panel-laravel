<x-app-layout title="Checkout" :crumbs="[['label' => 'Billing', 'url' => route('billing.invoices')], ['label' => 'Checkout']]">
    @php
        $symbol = $currency === 'INR' ? '₹' : '$';
        $fmt = fn ($minor) => $symbol.number_format($minor / 100, 2);
    @endphp

    <div class="page-head">
        <div>
            <h1>Review your order</h1>
            <p>Amounts are calculated on the server from the plan catalogue. Nothing here is taken from the browser.</p>
        </div>
    </div>

    <div class="grid cols-2" style="gap:20px;align-items:start">
        <div class="card">
            <div class="card-head"><h2>{{ $plan->name }} licence</h2><span class="badge badge-info">{{ $plan->edition }}</span></div>
            <div class="card-body">
                <form method="GET" action="{{ route('billing.checkout') }}" class="row wrap" style="gap:10px;margin-bottom:18px">
                    <input type="hidden" name="plan" value="{{ $plan->slug }}">
                    <select name="interval" class="select" style="max-width:220px" onchange="this.form.submit()">
                        @foreach ($intervals as $i)
                            <option value="{{ $i }}" @selected($i === $interval)>
                                {{ ucfirst(str_replace('_', ' ', $i)) }} — {{ \App\Domain\Billing\Pricing::INTERVAL_MONTHS[$i] }} month(s)
                            </option>
                        @endforeach
                    </select>
                    <select name="currency" class="select" style="max-width:140px" onchange="this.form.submit()">
                        <option value="INR" @selected($currency === 'INR')>INR</option>
                        <option value="USD" @selected($currency === 'USD')>USD</option>
                    </select>
                </form>

                <table class="table">
                    <tbody>
                        <tr><td>{{ $plan->name }} · {{ str_replace('_', ' ', $interval) }}</td><td class="num text-right">{{ $fmt($priced['subtotal'] + $priced['period_discount']) }}</td></tr>
                        @if ($priced['period_discount'] > 0)
                            <tr><td class="muted">Multi-period discount</td><td class="num text-right" style="color:var(--ok)">− {{ $fmt($priced['period_discount']) }}</td></tr>
                        @endif
                        <tr><td class="cell-strong">Subtotal</td><td class="num text-right cell-strong">{{ $fmt($priced['subtotal']) }}</td></tr>
                        @if ($tax['is_export'])
                            <tr><td class="muted">Export — zero rated (LUT)</td><td class="num text-right">—</td></tr>
                        @elseif ($tax['igst'] > 0)
                            <tr><td class="muted">IGST {{ $tax['rate_basis_points'] / 100 }}%</td><td class="num text-right">{{ $fmt($tax['igst']) }}</td></tr>
                        @else
                            <tr><td class="muted">CGST {{ $tax['rate_basis_points'] / 200 }}%</td><td class="num text-right">{{ $fmt($tax['cgst']) }}</td></tr>
                            <tr><td class="muted">SGST {{ $tax['rate_basis_points'] / 200 }}%</td><td class="num text-right">{{ $fmt($tax['sgst']) }}</td></tr>
                        @endif
                        <tr><td class="cell-strong" style="font-size:1rem">Total</td><td class="num text-right cell-strong" style="font-size:1rem">{{ $fmt($total) }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Confirm</h2></div>
            <form method="POST" action="{{ route('billing.store') }}" class="card-body">
                @csrf
                <input type="hidden" name="plan" value="{{ $plan->slug }}">
                <input type="hidden" name="interval" value="{{ $interval }}">
                <input type="hidden" name="currency" value="{{ $currency }}">

                <x-input label="Coupon code (optional)" name="coupon" placeholder="LAUNCH10" />

                <p class="small muted mt-2">
                    One licence activates on one server. You can move it later by deactivating the old server.
                </p>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-block">Continue to payment · {{ $fmt($total) }}</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
