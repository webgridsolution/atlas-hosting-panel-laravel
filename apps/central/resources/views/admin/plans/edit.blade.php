<x-app-layout :title="'Edit '.$plan->name" :crumbs="[['label' => 'Plans', 'url' => route('admin.plans.index')], ['label' => $plan->name]]">
    <div class="page-head">
        <div>
            <h1>Edit {{ $plan->name }}</h1>
            <p>{{ $plan->licenses_count ?? $plan->licenses()->count() }} licences reference this plan. Editing entitlements affects future activations.</p>
        </div>
    </div>

    <x-alert variant="warn" class="mb-2">
        Licence tokens already issued keep their snapshot until the next heartbeat refresh. Expect up to 24 hours for entitlement changes to reach every server.
    </x-alert>

    <form method="POST" action="{{ route('admin.plans.update', $plan) }}">
        @csrf
        @method('PATCH')
        @include('admin.plans._form', ['plan' => $plan, 'features' => $features])

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('admin.plans.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</x-app-layout>
