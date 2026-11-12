<x-app-layout title="Subscriptions" :crumbs="[['label' => 'Billing', 'url' => route('billing.invoices')], ['label' => 'Subscriptions']]">
    <div class="page-head">
        <div>
            <h1>Subscriptions</h1>
            <p>Recurring licences and when their current period ends.</p>
        </div>
    </div>

    @if ($subscriptions->isEmpty())
        <x-empty icon="refresh" title="No subscriptions" description="Buying a licence creates a subscription that tracks its renewal period.">
            <a href="{{ route('pricing') }}" class="btn btn-primary btn-sm">See plans</a>
        </x-empty>
    @else
        <div class="card">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Plan</th><th>Licence</th><th>Interval</th><th>Status</th><th>Period ends</th><th class="text-right">Amount</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($subscriptions as $subscription)
                            <tr>
                                <td class="cell-strong">{{ $subscription->plan->name }}</td>
                                <td class="mono small">{{ $subscription->license?->public_id ?? '—' }}</td>
                                <td>{{ ucfirst(str_replace('_', ' ', $subscription->interval)) }}</td>
                                <td>
                                    <span class="badge {{ $subscription->isActive() ? 'badge-ok' : 'badge-warn' }}">
                                        {{ ucfirst($subscription->status) }}
                                    </span>
                                    @if ($subscription->cancel_at_period_end)
                                        <span class="cell-sub">Cancels at period end</span>
                                    @endif
                                </td>
                                <td class="num">
                                    {{ $subscription->current_period_end->format('d M Y') }}
                                    <span class="cell-sub">{{ $subscription->current_period_end->diffForHumans() }}</span>
                                </td>
                                <td class="num text-right">{{ $subscription->currency === 'INR' ? '₹' : '$' }}{{ number_format($subscription->amount_minor / 100, 2) }}</td>
                                <td class="text-right">
                                    @if ($subscription->license)
                                        <form method="POST" action="{{ route('billing.renew', $subscription->license) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-secondary btn-sm">Renew now</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $subscriptions->links() }}
        </div>

        <x-alert variant="info" class="mt-3">
            Automatic card/UPI mandates arrive with the subscription-billing release. Until then, renewal is a button:
            paying early extends the existing period rather than starting a new one.
        </x-alert>
    @endif
</x-app-layout>
