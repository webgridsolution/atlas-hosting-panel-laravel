<x-app-layout :title="$invoice->number" :crumbs="[['label' => 'Invoices', 'url' => route('admin.invoices.index')], ['label' => $invoice->number]]">
    @php
        $s = $invoice->currency === 'INR' ? '₹' : '$';
        $fmt = fn ($m) => $s.number_format($m / 100, 2);
        $refunded = $refunds->sum('amount_minor');
    @endphp

    <div class="page-head">
        <div>
            <h1 class="mono">{{ $invoice->number }}</h1>
            <p>{{ $invoice->buyer_name }} · issued {{ $invoice->issued_at->format('d M Y') }}</p>
        </div>
        <div class="page-actions">
            <x-badge variant="{{ $invoice->status === 'paid' ? 'ok' : 'warn' }}">{{ ucfirst($invoice->status) }}</x-badge>
        </div>
    </div>

    <div class="grid cols-3" style="gap:20px;align-items:start">
        <div class="card" style="grid-column:span 2">
            <div class="card-head"><h2>Line items</h2></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Description</th><th>SAC</th><th class="text-right">Taxable</th><th class="text-right">CGST</th><th class="text-right">SGST</th><th class="text-right">IGST</th></tr></thead>
                    <tbody>
                        @foreach ($invoice->items as $item)
                            <tr>
                                <td class="cell-strong">{{ $item->description }}</td>
                                <td class="mono">{{ $item->sac_code }}</td>
                                <td class="num text-right">{{ $fmt($item->taxable_minor) }}</td>
                                <td class="num text-right">{{ $fmt($item->cgst_minor) }}</td>
                                <td class="num text-right">{{ $fmt($item->sgst_minor) }}</td>
                                <td class="num text-right">{{ $fmt($item->igst_minor) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-foot row between">
                <span class="small muted">Place of supply {{ $invoice->place_of_supply }}{{ $invoice->is_export ? ' · zero-rated export' : '' }}</span>
                <span class="cell-strong">Total {{ $fmt($invoice->total_minor) }}</span>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Payment</h2></div>
            <div class="card-body stack" style="--stack:12px">
                @if ($invoice->payment)
                    <div>
                        <div class="eyebrow">Provider reference</div>
                        <div class="mono small">{{ $invoice->payment->gateway_payment_id }}</div>
                    </div>
                    <div>
                        <div class="eyebrow">Method</div>
                        <div>{{ ucfirst($invoice->payment->method ?? 'Unknown') }}</div>
                    </div>
                    <div>
                        <div class="eyebrow">Refunded</div>
                        <div>{{ $fmt($refunded) }} of {{ $fmt($invoice->payment->amount_minor) }}</div>
                    </div>

                    @can('create', App\Models\Refund::class)
                        @if ($refunded < $invoice->payment->amount_minor)
                            <details>
                                <summary class="btn btn-secondary btn-sm" style="cursor:pointer">Issue refund</summary>
                                <form method="POST" action="{{ route('admin.payments.refund', $invoice->payment) }}" class="mt-2">
                                    @csrf
                                    <x-input label="Amount (minor units)" name="amount_minor" type="number"
                                             :value="$invoice->payment->amount_minor - $refunded" required
                                             hint="Enter paise/cents. Max {{ $invoice->payment->amount_minor - $refunded }}." />
                                    <x-textarea label="Reason (recorded in the audit log)" name="reason" rows="3" required />
                                    <button class="btn btn-danger btn-block">Refund via Razorpay</button>
                                </form>
                            </details>
                        @endif
                    @endcan
                @else
                    <p class="small muted">No payment attached.</p>
                @endif
            </div>
        </div>
    </div>

    @if ($refunds->isNotEmpty())
        <div class="card mt-3">
            <div class="card-head"><h2>Refund history</h2></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Refund</th><th>Provider id</th><th class="text-right">Amount</th><th>Reason</th><th>Processed</th></tr></thead>
                    <tbody>
                        @foreach ($refunds as $refund)
                            <tr>
                                <td class="mono">{{ $refund->public_id }}</td>
                                <td class="mono small">{{ $refund->gateway_refund_id }}</td>
                                <td class="num text-right">{{ $fmt($refund->amount_minor) }}</td>
                                <td class="small muted">{{ $refund->reason }}</td>
                                <td class="num small">{{ $refund->processed_at?->format('d M Y H:i') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-app-layout>
