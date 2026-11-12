<x-app-layout title="Settings" :crumbs="[['label' => 'Settings']]">
    <div class="page-head">
        <div>
            <h1>Settings</h1>
            <p>Operational values used by invoicing, licensing and the release feed. Every change is audited.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.settings.update') }}" style="max-width:760px">
        @csrf @method('PATCH')

        @foreach ($fields->groupBy('group') as $group => $items)
            <div class="card mb-3">
                <div class="card-head"><h2>{{ ucfirst($group) }}</h2></div>
                <div class="card-body">
                    @foreach ($items as $field)
                        <x-input
                            :label="$field['label']"
                            :name="'settings['.$field['key'].']'"
                            :value="$field['value']"
                            :type="$field['type'] === 'integer' ? 'number' : 'text'"
                            :hint="$field['key']"
                            :disabled="! $canManage"
                        />
                    @endforeach
                </div>
            </div>
        @endforeach

        @if ($canManage)
            <div class="form-actions" style="justify-content:flex-start">
                <button class="btn btn-primary">Save settings</button>
            </div>
        @else
            <x-alert variant="info">You have read-only access to settings.</x-alert>
        @endif
    </form>

    <div class="card mt-3" style="max-width:760px">
        <div class="card-head"><h2>Environment secrets</h2></div>
        <div class="card-body">
            <p class="small muted mb-2">
                Payment keys and the webhook secret are environment values, not database rows — they must never be readable
                from the admin UI or written to the audit log.
            </p>
            <div class="kv"><span>RAZORPAY_KEY_ID</span><span class="mono">{{ filled(config('services.razorpay.key_id')) ? 'configured' : 'missing' }}</span></div>
            <div class="kv"><span>RAZORPAY_KEY_SECRET</span><span class="mono">{{ filled(config('services.razorpay.key_secret')) ? 'configured' : 'missing' }}</span></div>
            <div class="kv"><span>RAZORPAY_WEBHOOK_SECRET</span><span class="mono">{{ filled(config('services.razorpay.webhook_secret')) ? 'configured' : 'missing' }}</span></div>
            <div class="kv"><span>ATLAS_PAYMENTS_DRIVER</span><span class="mono">{{ config('services.payments.driver', 'razorpay') }}</span></div>
        </div>
    </div>
</x-app-layout>
