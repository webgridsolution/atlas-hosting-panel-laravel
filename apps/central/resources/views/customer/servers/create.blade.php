<x-app-layout title="Register server" :crumbs="[['label' => 'Servers', 'url' => route('customer.servers.index')], ['label' => 'Register']]">
    <div class="page-head">
        <div>
            <h1>Register a server</h1>
            <p>Record the VPS you plan to install on. The panel reports its real fingerprint at activation; this record is your inventory.</p>
        </div>
    </div>

    <div class="grid cols-2" style="gap:24px;align-items:start">
        <div class="card">
            <div class="card-head"><h2>Server details</h2></div>
            <form method="POST" action="{{ route('customer.servers.store') }}" class="card-body">
                @csrf

                <x-input label="Hostname" name="hostname" required autofocus
                         placeholder="panel.example.com"
                         hint="A fully-qualified name that resolves to this server's IP." />

                <div class="mt-2">
                    <x-input label="IPv4 address (optional)" name="ip_address" placeholder="203.0.113.10" />
                </div>

                <div class="mt-2">
                    <x-select label="Operating system" name="os" :selected="old('os', 'ubuntu-24.04')" :options="[
                        'ubuntu-24.04' => 'Ubuntu 24.04 LTS',
                        'ubuntu-26.04' => 'Ubuntu 26.04 LTS',
                        'ubuntu-22.04' => 'Ubuntu 22.04 LTS (legacy)',
                    ]" />
                </div>

                <div class="mt-2">
                    <x-select label="Attach licence" name="license_id" :selected="old('license_id')"
                              :options="$licenses->mapWithKeys(fn ($l) => [$l->id => $l->plan->name.' · '.$l->maskedKey()])->all()"
                              placeholder="No licence yet" />
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Register server</button>
                    <a href="{{ route('customer.servers.index') }}" class="btn btn-ghost">Cancel</a>
                </div>
            </form>
        </div>

        <div class="stack" style="--stack:14px">
            <div class="card card-pad">
                <div class="eyebrow mb-1">Before installing</div>
                <ul class="stack small" style="--stack:9px;color:var(--ink-2)">
                    <li class="row items-start" style="gap:9px"><x-icon name="check" :size="14" style="color:var(--brand);margin-top:3px" /> Clean Ubuntu 24.04/26.04, no control panel installed</li>
                    <li class="row items-start" style="gap:9px"><x-icon name="check" :size="14" style="color:var(--brand);margin-top:3px" /> 2 GB RAM / 20 GB disk minimum</li>
                    <li class="row items-start" style="gap:9px"><x-icon name="check" :size="14" style="color:var(--brand);margin-top:3px" /> Ports 80 and 443 free</li>
                    <li class="row items-start" style="gap:9px"><x-icon name="check" :size="14" style="color:var(--brand);margin-top:3px" /> PTR record set to the hostname where possible</li>
                </ul>
            </div>

            <x-alert variant="warn" title="Mail needs port 25">
                Many providers block outbound port 25. Check with your provider before enabling the mail module, or delivery will fail silently.
            </x-alert>
        </div>
    </div>
</x-app-layout>
