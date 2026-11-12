<x-app-layout title="Subscriptions" :crumbs="[['label' => 'Subscriptions']]">
    <div class="page-head">
        <div>
            <h1>Subscriptions</h1>
            <p>Renewal periods behind each licence. A lapse never deletes customer data — it degrades the panel.</p>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Customer</th><th>Plan</th><th>Licence</th><th>Interval</th><th>Status</th><th>Period end</th><th class="text-right">Amount</th></tr></thead>
                <tbody>
                    @forelse ($subscriptions as $subscription)
                        <tr>
                            <td>
                                <span class="cell-strong">{{ $subscription->customer->user->name }}</span>
                                <span class="cell-sub">{{ $subscription->customer->user->email }}</span>
                            </td>
                            <td>{{ $subscription->plan->name }}</td>
                            <td class="mono small">
                                @if ($subscription->license)
                                    <a href="{{ route('admin.licenses.show', $subscription->license) }}">{{ $subscription->license->public_id }}</a>
                                @else — @endif
                            </td>
                            <td class="small">{{ ucfirst(str_replace('_', ' ', $subscription->interval)) }}</td>
                            <td><x-badge :variant="$subscription->isActive() ? 'ok' : 'warn'">{{ ucfirst($subscription->status) }}</x-badge></td>
                            <td class="num small">{{ $subscription->current_period_end->format('d M Y') }}</td>
                            <td class="num text-right">{{ $subscription->currency === 'INR' ? '₹' : '$' }}{{ number_format($subscription->amount_minor / 100, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center muted" style="padding:28px">No subscriptions yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($subscriptions->hasPages()) {{ $subscriptions->links() }} @endif
    </div>
</x-app-layout>
