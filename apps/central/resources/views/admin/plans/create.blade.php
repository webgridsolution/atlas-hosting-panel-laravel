<x-app-layout title="New plan" :crumbs="[['label' => 'Plans', 'url' => route('admin.plans.index')], ['label' => 'New']]">
    <div class="page-head">
        <div>
            <h1>New plan</h1>
            <p>Define an edition, its monthly prices and its entitlements. Nothing is hardcoded in the application.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.plans.store') }}">
        @csrf
        @include('admin.plans._form', ['plan' => $plan, 'features' => $features])

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Create plan</button>
            <a href="{{ route('admin.plans.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</x-app-layout>
