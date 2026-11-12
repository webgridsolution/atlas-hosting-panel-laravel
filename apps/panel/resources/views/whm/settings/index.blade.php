@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    <h1>Settings</h1>
    <p class="small muted">These values change how the panel behaves right away. Ports come from the environment and are fixed at install time.</p>

    <div class="grid two">
        <form method="POST" action="{{ route('whm.settings.update') }}" class="card">
            @csrf
            @method('PATCH')

            <h2>Server</h2>

            <div class="form-row">
                <div>
                    <label for="server_hostname">Hostname</label>
                    <input id="server_hostname" name="server_hostname" type="text" value="{{ $settings['server_hostname'] }}">
                </div>
            </div>

            <div class="form-row">
                <div>
                    <label for="server_ip">Public IPv4</label>
                    <input id="server_ip" name="server_ip" type="text" value="{{ $settings['server_ip'] }}" placeholder="203.0.113.10">
                    <div class="hint">Used for new DNS zones (A records).</div>
                </div>
            </div>

            <div class="form-row">
                <div>
                    <label for="nameservers">Nameservers</label>
                    <input id="nameservers" name="nameservers" type="text" value="{{ $settings['nameservers'] }}" placeholder="ns1.example.com,ns2.example.com">
                    <div class="hint">Comma separated, published as NS records in new zones.</div>
                </div>
            </div>

            <h2 style="margin-top:22px">Licence behaviour</h2>

            <div class="form-row">
                <div>
                    <label for="licence_grace_days">Grace days after expiry</label>
                    <input id="licence_grace_days" name="licence_grace_days" type="number" min="0" max="120" value="{{ $settings['licence_grace_days'] }}">
                    <div class="hint">During grace everything works. After it, the panel becomes read-only — never destructive.</div>
                </div>
            </div>

            <label class="checkbox" style="margin-bottom:16px">
                <input type="checkbox" name="suspend_on_expiry" value="1" @checked($settings['suspend_on_expiry'] === '1')>
                Suspend new provisioning when the licence is unusable (websites keep serving)
            </label>

            <button class="btn primary" type="submit">Save settings</button>
        </form>

        <div class="card">
            <h2>This installation</h2>
            <dl class="kv">
                <dt>Server ID</dt><dd class="mono small">{{ $settings['server_id'] ?: 'not registered' }}</dd>
                <dt>WHM port</dt><dd class="mono">{{ $ports['whm'] }}</dd>
                <dt>Panel port</dt><dd class="mono">{{ $ports['panel'] }}</dd>
                <dt>Webmail port</dt><dd class="mono">{{ $ports['webmail'] }}</dd>
                <dt>Agent socket</dt><dd class="mono small">{{ config('atlas.agent.socket') }}</dd>
                <dt>Agent driver</dt><dd class="mono small">{{ config('atlas.agent.driver') }}</dd>
            </dl>

            <p class="small faint" style="margin-top:16px">
                Ports are illustrative defaults. Change them by editing <span class="mono">.env</span> and the
                nginx server blocks, then restart the panel — the installer prints the URLs it actually used.
            </p>
        </div>
    </div>
@endsection
