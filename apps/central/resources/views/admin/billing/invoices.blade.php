<x-app-layout title="Invoices" :crumbs="[['label' => 'Invoices']]">
    <div class="page-head">
        <div>
            <h1>Invoices</h1>
            <p>Sequential per financial year, immutable once issued. Credit notes are separate documents.</p>
        </div>
    </div>

    <div class="stat-grid mb-3">
        <x-stat label="Licence revenue (net)" :value="'₹'.number_format($revenueMinor / 100, 2)" meta="excludes tax collected" />
        <x-stat label="Trial balance" :value="$trialBalanceMinor === 0 ? 'Balanced' : '₹'.number_format($trialBalanceMinor / 100, 2)"
                :meta="$trialBalanceMinor === 0 ? 'debits equal credits' : 'investigate — ledger must net to zero'" />
        <x-stat label="Invoices" :value="number_format($invoices->total())" meta="all time" />
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Invoice</th><th>Customer</th><th>Issued</th><th>Status</th><th class="text-right">Tax</th><th class="text-right">Total</th><th></th></tr></thead>
                <tbody>
                    @foreach ($invoices as $invoice)
                        <tr>
                            <td class="mono cell-strong">{{ $invoice->number }}</td>
                            <td>
                                <span>{{ $invoice->buyer_name }}</span>
                                <span class="cell-sub">{{ $invoice->buyer_email }}</span>
                            </td>
                            <td class="num small">{{ $invoice->issued_at->format('d M Y') }}</td>
                            <td><x-badge :variant="$invoice->status === 'paid' ? 'ok' : ($invoice->status === 'refunded' ? 'warn' : 'neutral')">{{ ucfirst($invoice->status) }}</x-badge></td>
                            <td class="num text-right">{{ $invoice->currency === 'INR' ? '₹' : '$' }}{{ number_format($invoice->tax_minor / 100, 2) }}</td>
                            <td class="num text-right cell-strong">{{ $invoice->formattedTotal() }}</td>
                            <td class="text-right"><a href="{{ route('admin.invoices.show', $invoice) }}" class="btn btn-secondary btn-sm">Open</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($invoices->hasPages()) {{ $invoices->links() }} @endif
    </div>
</x-app-layout>
