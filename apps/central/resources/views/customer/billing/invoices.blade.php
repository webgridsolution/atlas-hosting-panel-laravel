<x-app-layout title="Invoices" :crumbs="[['label' => 'Billing', 'url' => route('billing.invoices')], ['label' => 'Invoices']]">
    <div class="page-head">
        <div>
            <h1>Invoices &amp; payments</h1>
            <p>Every paid order produces a sequential GST invoice you can download or print.</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('billing.subscriptions') }}" class="btn btn-secondary">Subscriptions</a>
            <a href="{{ route('pricing') }}" class="btn btn-primary"><x-icon name="plus" :size="15" /> Buy another licence</a>
        </div>
    </div>

    @if ($orders->isNotEmpty())
        <div class="card mb-3">
            <div class="card-head"><h2>Unfinished orders</h2></div>
            <div class="table-wrap">
                <table class="table">
                    <tbody>
                        @foreach ($orders as $order)
                            <tr>
                                <td class="mono cell-strong">{{ $order->number }}</td>
                                <td><span class="badge {{ $order->status === 'failed' ? 'badge-danger' : 'badge-warn' }}">{{ ucfirst($order->status) }}</span></td>
                                <td class="num text-right">{{ $order->currency === 'INR' ? '₹' : '$' }}{{ number_format($order->total_minor / 100, 2) }}</td>
                                <td class="text-right">
                                    @if ($order->isPayable())
                                        <a href="{{ route('billing.pay', $order) }}" class="btn btn-secondary btn-sm">Complete payment</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($invoices->isEmpty())
        <x-empty icon="receipt" title="No invoices yet" description="Invoices appear here as soon as a payment is captured.">
            <a href="{{ route('pricing') }}" class="btn btn-primary btn-sm">See plans</a>
        </x-empty>
    @else
        <div class="card">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Invoice</th><th>Date</th><th>Description</th><th>Status</th><th class="text-right">Total</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($invoices as $invoice)
                            <tr>
                                <td class="mono cell-strong">{{ $invoice->number }}</td>
                                <td class="num">{{ $invoice->issued_at->format('d M Y') }}</td>
                                <td class="small muted">Software licence · SAC 997331</td>
                                <td><span class="badge {{ $invoice->status === 'paid' ? 'badge-ok' : ($invoice->status === 'refunded' ? 'badge-warn' : 'badge-neutral') }}">{{ ucfirst($invoice->status) }}</span></td>
                                <td class="num text-right">{{ $invoice->formattedTotal() }}</td>
                                <td class="text-right">
                                    <a href="{{ route('billing.invoice', $invoice) }}" class="btn btn-secondary btn-sm">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $invoices->links() }}
        </div>
    @endif
</x-app-layout>
