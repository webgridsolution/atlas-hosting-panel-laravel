<x-app-layout title="Coupons" :crumbs="[['label' => 'Coupons']]">
    <div class="page-head">
        <div>
            <h1>Coupons</h1>
            <p>Discounts apply to the licence subtotal before tax. Redemption counts are incremented inside the checkout transaction.</p>
        </div>
    </div>

    <div class="grid cols-3" style="gap:20px;align-items:start">
        @can('create', App\Models\Coupon::class)
            <div class="card">
                <div class="card-head"><h2>New coupon</h2></div>
                <form method="POST" action="{{ route('admin.coupons.store') }}" class="card-body">
                    @csrf
                    <x-input label="Code" name="code" required placeholder="LAUNCH10" hint="Letters and numbers, stored uppercase" />
                    <x-select label="Type" name="kind" required>
                        <option value="percent">Percentage off</option>
                        <option value="fixed">Fixed amount off</option>
                    </x-select>
                    <x-input label="Value" name="value" type="number" required hint="Percent for percentage coupons, whole currency units for fixed" />
                    <x-select label="Currency (fixed only)" name="currency">
                        <option value="INR">INR</option>
                        <option value="USD">USD</option>
                    </x-select>
                    <x-input label="Max redemptions" name="max_redemptions" type="number" hint="Leave empty for unlimited" />
                    <x-input label="Ends on" name="ends_at" type="date" />
                    <div class="form-actions"><button class="btn btn-primary btn-block">Create coupon</button></div>
                </form>
            </div>
        @endcan

        <div class="card" style="grid-column:span 2">
            <div class="card-head"><h2>Existing coupons</h2></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Code</th><th>Discount</th><th>Redeemed</th><th>Ends</th><th>State</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($coupons as $coupon)
                            <tr>
                                <td class="mono cell-strong">{{ $coupon->code }}</td>
                                <td>
                                    @if ($coupon->kind === 'percent')
                                        {{ rtrim(rtrim(number_format($coupon->value / 100, 2), '0'), '.') }}% off
                                    @else
                                        {{ $coupon->currency === 'INR' ? '₹' : '$' }}{{ number_format($coupon->value / 100, 2) }} off
                                    @endif
                                </td>
                                <td class="num">{{ $coupon->redeemed_count }}{{ $coupon->max_redemptions ? ' / '.$coupon->max_redemptions : '' }}</td>
                                <td class="num small">{{ $coupon->ends_at?->format('d M Y') ?? '—' }}</td>
                                <td><x-badge :variant="$coupon->isRedeemable() ? 'ok' : 'neutral'">{{ $coupon->is_active ? 'Active' : 'Disabled' }}</x-badge></td>
                                <td class="text-right">
                                    @can('update', $coupon)
                                        <form method="POST" action="{{ route('admin.coupons.toggle', $coupon) }}">
                                            @csrf @method('PATCH')
                                            <button class="btn btn-ghost btn-sm">{{ $coupon->is_active ? 'Disable' : 'Enable' }}</button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center muted" style="padding:24px">No coupons yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($coupons->hasPages()) {{ $coupons->links() }} @endif
        </div>
    </div>
</x-app-layout>
