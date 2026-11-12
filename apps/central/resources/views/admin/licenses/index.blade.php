<x-app-layout title="Licences" :crumbs="[['label' => 'Licences']]">
    <div class="page-head">
        <div>
            <h1>Licences</h1>
            <p>Every activation is bound to a server fingerprint. Status reflects the commercial state, not server health.</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('admin.licenses.create') }}" class="btn btn-primary"><x-icon name="plus" :size="15" /> Issue licence</a>
        </div>
    </div>

    <div class="row wrap mb-2" style="gap:6px">
        <a href="{{ route('admin.licenses.index') }}" class="chip {{ $status === '' ? 'badge-ok' : '' }}" style="font-weight:{{ $status === '' ? '600' : '400' }}">All</a>
        @foreach ($statuses as $value => $label)
            <a href="{{ route('admin.licenses.index', ['status' => $value]) }}"
               class="chip" style="font-weight:{{ $status === $value ? '600' : '400' }}">{{ $label }}</a>
        @endforeach
    </div>

    @if ($licenses->isEmpty())
        <x-empty icon="key" title="No licences in this view" description="Try a different filter, or issue a new licence.">
            <a href="{{ route('admin.licenses.create') }}" class="btn btn-primary btn-sm">Issue licence</a>
        </x-empty>
    @else
        <div class="card">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Licence</th>
                            <th>Customer</th>
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
                                <td class="mono">{{ $license->public_id }}</td>
                                <td>
                                    <span class="cell-strong">{{ $license->customer->user->name }}</span>
                                    <span class="cell-sub">{{ $license->customer->user->email }}</span>
                                </td>
                                <td>{{ $license->plan->name }}</td>
                                <td><x-status-badge :license="$license" /></td>
                                <td class="num">{{ $license->expires_at?->format('d M Y') ?? 'Lifetime' }}</td>
                                <td class="num">{{ $license->servers->count() }} / {{ $license->activation_limit }}</td>
                                <td class="text-right">
                                    <a href="{{ route('admin.licenses.show', $license) }}" class="btn btn-secondary btn-sm">Open</a>
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
