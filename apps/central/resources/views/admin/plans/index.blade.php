<x-app-layout title="Plans & pricing" :crumbs="[['label' => 'Plans & pricing']]">
    <div class="page-head">
        <div>
            <h1>Plans &amp; pricing</h1>
            <p>The catalogue that drives the public pricing page, checkout and licence entitlements.</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('admin.plans.create') }}" class="btn btn-primary"><x-icon name="plus" :size="15" /> New plan</a>
        </div>
    </div>

    @if ($plans->isEmpty())
        <x-empty icon="box" title="No plans yet" description="Create the first edition to publish pricing.">
            <a href="{{ route('admin.plans.create') }}" class="btn btn-primary btn-sm">Create plan</a>
        </x-empty>
    @else
        <div class="card">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Plan</th>
                            <th>Edition</th>
                            <th class="text-right">INR / mo</th>
                            <th class="text-right">USD / mo</th>
                            <th class="text-right">Accounts</th>
                            <th>Licences</th>
                            <th>State</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($plans as $plan)
                            @php
                                $inr = $plan->prices->firstWhere(fn ($p) => $p->currency === 'INR');
                                $usd = $plan->prices->firstWhere(fn ($p) => $p->currency === 'USD');
                                $accounts = $plan->entitlement('max_accounts');
                            @endphp
                            <tr>
                                <td>
                                    <span class="cell-strong">{{ $plan->name }}</span>
                                    <span class="cell-sub">{{ $plan->tagline }}</span>
                                </td>
                                <td class="mono small">{{ $plan->edition }}</td>
                                <td class="num text-right">{{ $inr?->formatted() ?? '—' }}</td>
                                <td class="num text-right">{{ $usd?->formatted() ?? '—' }}</td>
                                <td class="num text-right">{{ $accounts === -1 ? '∞' : number_format((int) $accounts) }}</td>
                                <td class="num">{{ $plan->licenses_count }}</td>
                                <td>
                                    <span class="badge {{ $plan->is_active ? 'badge-ok' : 'badge-neutral' }}">{{ $plan->is_active ? 'Active' : 'Hidden' }}</span>
                                    @if ($plan->is_featured)
                                        <span class="badge badge-info" style="margin-left:4px">Featured</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('admin.plans.edit', $plan) }}" class="btn btn-secondary btn-sm">Edit</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-app-layout>
