<x-app-layout title="Licences" :crumbs="[['label' => 'Licences']]">
    <div class="page-head">
        <div>
            <h1>Licences</h1>
            <p>Each licence activates on exactly one server. Status here reflects billing state, not your server's health.</p>
        </div>
    </div>

    @if ($licenses->isEmpty())
        <x-empty icon="key" title="No licences yet" description="Once a licence is issued to your account it will appear here with its activation state.">
            <a href="{{ route('pricing') }}" class="btn btn-secondary btn-sm">Browse editions</a>
        </x-empty>
    @else
        <div class="card">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Licence</th>
                            <th>Plan</th>
                            <th>Status</th>
                            <th>Expires</th>
                            <th>Servers</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($licenses as $license)
                            <tr>
                                <td>
                                    <span class="cell-strong mono">{{ $license->maskedKey() }}</span>
                                    <span class="cell-sub">{{ $license->public_id }}</span>
                                </td>
                                <td>
                                    <span class="cell-strong">{{ $license->plan->name }}</span>
                                    <span class="cell-sub">{{ $license->plan->edition }}</span>
                                </td>
                                <td><x-status-badge :license="$license" /></td>
                                <td class="num">
                                    {{ $license->expires_at?->format('d M Y') ?? 'Lifetime' }}
                                    @if ($license->daysUntilExpiry() !== null && $license->daysUntilExpiry() >= 0)
                                        <span class="cell-sub">{{ $license->daysUntilExpiry() }} days left</span>
                                    @endif
                                </td>
                                <td class="num">{{ $license->servers->count() }} / {{ $license->activation_limit }}</td>
                                <td class="text-right">
                                    <a href="{{ route('customer.licenses.show', $license) }}" class="btn btn-secondary btn-sm">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $licenses->links() }}
        </div>
    @endif
</x-app-layout>
