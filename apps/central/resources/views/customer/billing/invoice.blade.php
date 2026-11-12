<x-app-layout :title="$invoice->number" :crumbs="[['label' => 'Invoices', 'url' => route('billing.invoices')], ['label' => $invoice->number]]">
    @php
        $s = $invoice->currency === 'INR' ? '₹' : '$';
        $fmt = fn ($m) => $s.number_format($m / 100, 2);
        $cgst = $invoice->items->sum('cgst_minor');
        $sgst = $invoice->items->sum('sgst_minor');
        $igst = $invoice->items->sum('igst_minor');
    @endphp

    <div class="page-head">
        <div>
            <h1 class="mono">{{ $invoice->number }}</h1>
            <p>Issued {{ $invoice->issued_at->format('d M Y') }} · {{ $invoice->status === 'paid' ? 'Paid' : ucfirst($invoice->status) }}</p>
        </div>
        <div class="page-actions">
            <button type="button" class="btn btn-secondary" onclick="window.print()"><x-icon name="download" :size="15" /> Print / save PDF</button>
            <a href="{{ route('billing.invoices') }}" class="btn btn-ghost">Back</a>
        </div>
    </div>

    <div class="card card-pad" style="max-width:820px">
        <div class="row between wrap" style="align-items:flex-start;gap:24px">
            <div>
                <div class="eyebrow">Seller</div>
                <div class="cell-strong mt-1">{{ $invoice->seller_snapshot['name'] ?? config('app.name') }}</div>
                <div class="small muted">{{ $invoice->seller_snapshot['address'] ?? '' }}</div>
                <div class="small muted mono">GSTIN {{ $invoice->seller_snapshot['gstin'] ?: '—' }}</div>
            </div>
            <div>
                <div class="eyebrow">Billed to</div>
                <div class="cell-strong mt-1">{{ $invoice->buyer_name }}</div>
                <div class="small muted">{{ $invoice->buyer_email }}</div>
                <div class="small muted mono">{{ $invoice->buyer_gstin ? 'GSTIN '.$invoice->buyer_gstin : 'Place of supply: '.$invoice->place_of_supply }}</div>
            </div>
            <div class="text-right">
                <div class="eyebrow">Invoice</div>
                <div class="mono mt-1">{{ $invoice->number }}</div>
                <div class="small muted">FY {{ $invoice->financial_year }}</div>
            </div>
        </div>

        <hr class="divider mt-3 mb-3">

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Description</th><th>SAC</th><th class="text-right">Qty</th><th class="text-right">Rate</th><th class="text-right">Amount</th></tr>
                </thead>
                <tbody>
                    @foreach ($invoice->items as $item)
                        <tr>
                            <td class="cell-strong">{{ $item->description }}</td>
                            <td class="mono">{{ $item->sac_code }}</td>
                            <td class="num text-right">{{ $item->quantity }}</td>
                            <td class="num text-right">{{ $fmt($item->unit_price_minor) }}</td>
                            <td class="num text-right">{{ $fmt($item->taxable_minor) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="totals">
            <table>
                <tbody class="small">
                    <tr><td class="muted" style="padding:4px 0">Subtotal</td><td class="num text-right">{{ $fmt($invoice->subtotal_minor) }}</td></tr>
                    @if ($invoice->discount_minor > 0)
                        <tr><td class="muted" style="padding:4px 0">Discount</td><td class="num text-right">− {{ $fmt($invoice->discount_minor) }}</td></tr>
                    @endif
                    @if ($igst > 0)
                        <tr><td class="muted" style="padding:4px 0">IGST</td><td class="num text-right">{{ $fmt($igst) }}</td></tr>
                    @elseif ($cgst > 0)
                        <tr><td class="muted" style="padding:4px 0">CGST</td><td class="num text-right">{{ $fmt($cgst) }}</td></tr>
                        <tr><td class="muted" style="padding:4px 0">SGST</td><td class="num text-right">{{ $fmt($sgst) }}</td></tr>
                    @else
                        <tr><td class="muted" style="padding:4px 0">Tax</td><td class="num text-right">{{ $invoice->is_export ? 'Zero-rated export' : '—' }}</td></tr>
                    @endif
                    <tr><td class="cell-strong" style="padding:8px 0;border-top:1px solid var(--line)">Total</td><td class="num text-right cell-strong" style="border-top:1px solid var(--line)">{{ $fmt($invoice->total_minor) }}</td></tr>
                </tbody>
            </table>
        </div>

        @if ($invoice->payment)
            <p class="small muted mt-3">
                Paid via {{ ucfirst($invoice->payment->method ?? 'Razorpay') }} · provider reference
                <span class="mono">{{ $invoice->payment->gateway_payment_id }}</span>
            </p>
        @endif

        <p class="hint mt-3">Computer-generated invoice. Software licence supplied electronically; no physical goods.</p>
    </div>
</x-app-layout>
