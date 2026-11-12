<x-app-layout title="Orders" :crumbs="[['label' => 'Orders']]">
    <div class="page-head">
        <div>
            <h1>Orders</h1>
            <p>Every checkout attempt, whatever the outcome. A paid order always has exactly one payment and one invoice.</p>
        </div>
    </div>

    <div class="row wrap mb-2" style="gap:6px">
        <a href="{{ route('admin.orders.index') }}" class="chip" style="font-weight:{{ ! $status ? '600' : '400' }}">All</a>
        @foreach (['pending' => 'Pending', 'paid' => 'Paid', 'failed' => 'Failed', 'cancelled' => 'Cancelled'] as $value => $label)
            <a href="{{ route('admin.orders.index', ['status' => $value]) }}" class="chip" style="font-weight:{{ $status === $value ? '600' : '400' }}">{{ $label }}</a>
        @endforeach
    </div>

    @if ($orders->isEmpty())
        <x-empty icon="receipt" title="No orders in this view" description="Orders appear when a customer starts a checkout." />
    @else
        <div class="card">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Order</th><th>Customer</th><th>Status</th><th>Gateway</th><th class="text-right">Total</th><th>Created</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($orders as $order)
                            <tr>
                                <td class="mono">{{ $order->number }}</td>
                                <td>
                                    <span class="cell-strong">{{ $order->customer->user->name }}</span>
                                    <span class="cell-sub">{{ $order->customer->user->email }}</span>
                                </td>
                                <td>
                                    <x-badge :variant="match($order->status) { 'paid' => 'ok', 'pending' => 'warn', 'failed' => 'danger', default => 'neutral' }">
                                        {{ ucfirst($order->status) }}
                                    </x-badge>
                                </td>
                                <td class="small">{{ ucfirst($order->gateway) }}<span class="cell-sub mono">{{ $order->gateway_order_id ?? '—' }}</span></td>
                                <td class="num text-right">{{ $order->currency === 'INR' ? '₹' : '$' }}{{ number_format($order->total_minor / 100, 2) }}</td>
                                <td class="num small">{{ $order->created_at->format('d M Y H:i') }}</td>
                                <td class="text-right">
                                    @if ($order->invoice)
                                        <a href="{{ route('admin.invoices.show', $order->invoice) }}" class="btn btn-secondary btn-sm">Invoice</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $orders->links() }}
        </div>
    @endif
</x-app-layout>
