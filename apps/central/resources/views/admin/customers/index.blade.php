<x-app-layout title="Customers" :crumbs="[['label' => 'Customers']]">
    <div class="page-head">
        <div>
            <h1>Customers</h1>
            <p>Everyone who can hold a licence. Staff accounts appear under their own role but are listed here too.</p>
        </div>
    </div>

    <form method="GET" action="{{ route('admin.customers.index') }}" class="row mb-2" style="gap:8px;max-width:460px">
        <input type="search" name="q" value="{{ $search }}" class="input" placeholder="Search name, email or company…">
        <button type="submit" class="btn btn-secondary"><x-icon name="search" :size="15" /></button>
        @if ($search !== '')
            <a href="{{ route('admin.customers.index') }}" class="btn btn-ghost">Clear</a>
        @endif
    </form>

    @if ($customers->isEmpty())
        <x-empty icon="users" title="No customers found"
                 :description="$search !== '' ? 'Nothing matched “'.$search.'”.' : 'Customers appear here after registration.'" />
    @else
        <div class="card">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Customer</th><th>Company</th><th>Country</th><th>Licences</th><th>Servers</th><th>Joined</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($customers as $customer)
                            <tr>
                                <td>
                                    <span class="cell-strong">{{ $customer->user->name }}</span>
                                    <span class="cell-sub">{{ $customer->user->email }}</span>
                                </td>
                                <td>{{ $customer->company_name ?? '—' }}</td>
                                <td class="mono">{{ $customer->country }}</td>
                                <td class="num">{{ $customer->licenses_count }}</td>
                                <td class="num">{{ $customer->servers_count }}</td>
                                <td class="num">{{ $customer->created_at->format('d M Y') }}</td>
                                <td class="text-right">
                                    <a href="{{ route('admin.customers.show', $customer) }}" class="btn btn-secondary btn-sm">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $customers->links() }}
        </div>
    @endif
</x-app-layout>
