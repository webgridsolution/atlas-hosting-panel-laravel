@php
    use App\Domain\Licensing\LicenseManager;

    $user = auth()->user();
    $area = $area ?? (
        $user?->hasRole('admin') ? 'whm' : ($user?->hasRole('reseller') ? 'reseller' : 'panel')
    );

    $licence = app(LicenseManager::class)->evaluate();
    $licenceWarn = in_array($licence['state'], ['grace', 'read_only', 'unlicensed'], true);

    $nav = [
        'whm' => [
            ['route' => 'whm.dashboard', 'label' => 'Overview', 'icon' => 'grid'],
            ['group' => 'Server'],
            ['route' => 'whm.accounts.index', 'label' => 'Hosting accounts', 'icon' => 'users'],
            ['route' => 'whm.packages.index', 'label' => 'Packages', 'icon' => 'package'],
            ['route' => 'whm.services.index', 'label' => 'Services', 'icon' => 'server'],
            ['route' => 'whm.audit.index', 'label' => 'Audit log', 'icon' => 'receipt'],
            ['group' => 'Panel'],
            ['route' => 'whm.settings.index', 'label' => 'Settings', 'icon' => 'settings'],
            ['route' => 'whm.licence.index', 'label' => 'Licence', 'icon' => 'shield'],
        ],
        'reseller' => [
            ['route' => 'reseller.dashboard', 'label' => 'Overview', 'icon' => 'grid'],
            ['route' => 'reseller.accounts.index', 'label' => 'My customers', 'icon' => 'users'],
            ['route' => 'reseller.accounts.create', 'label' => 'New account', 'icon' => 'plus'],
            ['group' => 'Account'],
            ['route' => 'profile.edit', 'label' => 'Your profile', 'icon' => 'user'],
        ],
        'panel' => [
            // The groups follow cPanel's own arrangement — Websites, Files, Email,
            // Databases, Security, Software, Advanced — because that is the map a
            // customer already has in their head from every other host they have
            // used. Every entry here is a screen that exists; nothing is a
            // placeholder, and the screens test enumerates exactly this list.
            ['route' => 'panel.dashboard', 'label' => 'Overview', 'icon' => 'grid'],

            ['group' => 'Websites'],
            ['route' => 'panel.domains.index', 'label' => 'Domains', 'icon' => 'globe'],
            ['route' => 'panel.websites.index', 'label' => 'Websites', 'icon' => 'server'],
            ['route' => 'panel.redirects.index', 'label' => 'Redirects', 'icon' => 'arrow-right'],
            ['route' => 'panel.dns.index', 'label' => 'DNS zone editor', 'icon' => 'network', 'when' => config('atlas.dns.enabled')],

            ['group' => 'Files'],
            ['route' => 'panel.files.index', 'label' => 'File manager', 'icon' => 'folder'],
            ['route' => 'panel.ftp.index', 'label' => 'FTP accounts', 'icon' => 'plug'],
            ['route' => 'panel.backups.index', 'label' => 'Backups', 'icon' => 'archive'],
            ['route' => 'panel.dev.index', 'label' => 'Git deploy', 'icon' => 'git'],
            ['route' => 'panel.usage.disk', 'label' => 'Disk usage', 'icon' => 'pie'],

            ['group' => 'Email'],
            ['route' => 'panel.email.index', 'label' => 'Email accounts', 'icon' => 'mail', 'when' => config('atlas.mail.enabled')],
            ['route' => 'panel.email.policy', 'label' => 'Routing, forwarders & spam', 'icon' => 'forward', 'when' => config('atlas.mail.enabled')],
            ['route' => 'panel.email.filters', 'label' => 'Email filters', 'icon' => 'filter', 'when' => config('atlas.mail.enabled')],
            ['route' => 'webmail.inbox', 'label' => 'Webmail', 'icon' => 'mail-open', 'when' => config('atlas.mail.enabled')],

            ['group' => 'Databases'],
            ['route' => 'panel.databases.index', 'label' => 'MySQL databases', 'icon' => 'database'],
            ['route' => 'panel.databases.users', 'label' => 'Database users', 'icon' => 'user-key'],

            ['group' => 'SSL & Security'],
            ['route' => 'panel.ssl.index', 'label' => 'SSL/TLS', 'icon' => 'lock'],
            ['route' => 'panel.security.index', 'label' => 'IP blocker & privacy', 'icon' => 'shield'],
            ['route' => 'panel.ssh.index', 'label' => 'SSH access', 'icon' => 'terminal'],
            ['route' => 'panel.security.twofactor', 'label' => 'Two-factor auth', 'icon' => 'key'],
            ['route' => 'panel.security.tokens', 'label' => 'API tokens', 'icon' => 'key-round'],

            ['group' => 'PHP & Software'],
            ['route' => 'panel.config.php', 'label' => 'PHP configuration', 'icon' => 'php'],
            ['route' => 'panel.config.extensions.all', 'label' => 'PHP extensions', 'icon' => 'puzzle'],
            ['route' => 'panel.config.mime', 'label' => 'MIME types', 'icon' => 'file'],
            ['route' => 'panel.config.errors', 'label' => 'Error pages', 'icon' => 'alert'],
            ['route' => 'panel.apps.index', 'label' => 'Applications', 'icon' => 'apps'],

            ['group' => 'Performance'],
            ['route' => 'panel.stats.index', 'label' => 'Resource usage', 'icon' => 'activity'],
            ['route' => 'panel.usage.bandwidth', 'label' => 'Bandwidth', 'icon' => 'chart'],

            ['group' => 'Advanced'],
            ['route' => 'panel.cron.index', 'label' => 'Cron jobs', 'icon' => 'clock'],
            ['route' => 'panel.logs.index', 'label' => 'Logs', 'icon' => 'receipt'],
            ['route' => 'panel.usage.logins', 'label' => 'Login history', 'icon' => 'clock-past'],

            ['group' => 'Account'],
            ['route' => 'profile.edit', 'label' => 'Profile & password', 'icon' => 'user'],
        ],
    ][$area] ?? [];
@endphp
<!DOCTYPE html>
<html lang="en" data-theme="{{ request()->cookie('atlas-theme', 'dark') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Atlas' }} · {{ $area === 'whm' ? 'WHM' : ($area === 'reseller' ? 'Reseller' : 'Panel') }}</title>
    <link rel="stylesheet" href="/assets/atlas.css">
</head>
<body>
<div class="shell">
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <span class="mark">A</span>
            <span>Atlas<small>{{ $area === 'whm' ? 'WHM console' : ($area === 'reseller' ? 'Reseller' : 'Hosting panel') }}</small></span>
        </div>

        <nav class="nav">
            @foreach ($nav as $item)
                @if (isset($item['group']))
                    <div class="nav-group">{{ $item['group'] }}</div>
                    @continue
                @endif

                @if (isset($item['when']) && ! $item['when'])
                    @continue
                @endif

                <a href="{{ route($item['route']) }}"
                   class="{{ request()->routeIs($item['route']) ? 'active' : '' }}">
                    <x-icon :name="$item['icon']" />
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </nav>
    </aside>

    <div class="main">
        @if (config('atlas.agent.driver') === 'sandbox')
            <div class="banner-demo">
                Sandbox agent — this panel is running without root and is not changing this machine. Run the installer on a VPS for live hosting.
            </div>
        @endif

        <header class="topbar">
            <div class="row">
                <button class="btn ghost sm" type="button" data-sidebar-toggle aria-controls="sidebar" aria-expanded="false" aria-label="Toggle navigation">
                    <x-icon name="menu" :size="18" />
                </button>
                <div class="crumbs">
                    {{-- @section('title') in each view; this is the one place it is read --}}
                    <strong>@yield('title', 'Dashboard')</strong>
                </div>
            </div>

            <div class="topbar-actions">
                @if ($area === 'whm')
                    <a href="{{ route('whm.licence.index') }}">
                        <span class="badge {{ $licence['state'] === 'active' ? 'ok' : ($licence['state'] === 'grace' ? 'warn' : 'danger') }}">
                            {{ str_replace('_', ' ', $licence['state']) }}
                        </span>
                    </a>
                @endif

                <a class="btn ghost sm" href="{{ route($area === 'whm' ? 'whm.accounts.index' : ($area === 'reseller' ? 'reseller.accounts.index' : 'panel.dashboard')) }}" title="Switch interface">
                    {{ $area === 'whm' ? 'Accounts' : ($area === 'reseller' ? 'Customers' : 'Home') }}
                </a>

                <button class="theme-toggle" type="button" data-theme-toggle aria-label="Toggle theme">
                    <x-icon name="sun" :size="16" />
                </button>

                <span class="avatar">{{ strtoupper(substr($user?->name ?? 'A', 0, 1)) }}</span>

                <form method="POST" action="{{ route('logout') }}" class="inline-form">
                    @csrf
                    <button class="btn ghost sm" type="submit"><x-icon name="logout" :size="15" /> Sign out</button>
                </form>
            </div>
        </header>

        <main class="content">
        @isset($panelBar)
            {{-- ---- the account strip: the facts a customer opens the panel for --}}
            <div class="infobar">
                <div class="infobar-item">
                    <span class="infobar-label">User</span>
                    <span class="infobar-value mono">{{ $panelBar['username'] }}</span>
                </div>
                <div class="infobar-item">
                    <span class="infobar-label">Primary domain</span>
                    <a class="infobar-value" href="{{ route('panel.domains.index') }}">{{ $panelBar['primary_domain'] }}</a>
                </div>
                <div class="infobar-item">
                    <span class="infobar-label">IP address</span>
                    <span class="infobar-value mono">{{ $panelBar['ip'] ?: 'not recorded' }}</span>
                </div>
                <div class="infobar-item">
                    <span class="infobar-label">Home</span>
                    <span class="infobar-value mono">{{ $panelBar['home'] }}</span>
                </div>
                <div class="infobar-item">
                    <span class="infobar-label">Last login</span>
                    <span class="infobar-value">
                        @if ($panelBar['last_login_at'])
                            {{ $panelBar['last_login_at']->format('d M H:i') }}
                            <span class="mono muted">{{ $panelBar['last_login_ip'] }}</span>
                        @else
                            first session
                        @endif
                    </span>
                </div>
                <div class="infobar-item">
                    <span class="infobar-label">Domains</span>
                    <span class="infobar-value">{{ $panelBar['domains'] }}</span>
                </div>
                <div class="infobar-item">
                    <span class="infobar-label">Databases</span>
                    <span class="infobar-value">{{ $panelBar['databases'] }}</span>
                </div>
                <div class="infobar-item">
                    <span class="infobar-label">Bandwidth this month</span>
                    <span class="infobar-value">
                        {{ $panelBar['bandwidth_mb'] >= 1024 ? number_format($panelBar['bandwidth_mb'] / 1024, 2).' GB' : number_format($panelBar['bandwidth_mb']).' MB' }}
                        @if ($panelBar['quota_mb'] > 0)
                            <span class="muted small">of {{ number_format($panelBar['quota_mb'] / 1024, 0) }} GB disk</span>
                        @endif
                    </span>
                </div>
            </div>
        @endisset

            @if ($licenceWarn && $area === 'whm')
                <div class="license-bar {{ $licence['state'] === 'read_only' ? 'danger' : '' }}">
                    <x-icon name="alert" :size="16" />
                    <span>{{ $licence['reason'] }}</span>
                    <a href="{{ route('whm.licence.index') }}" class="btn sm" style="margin-left:auto">Licence</a>
                </div>
            @endif

            @if (session('status'))
                <div class="alert ok"><x-icon name="check" :size="16" /><div>{{ session('status') }}</div></div>
            @endif

            @if (session('error'))
                <div class="alert error"><x-icon name="alert" :size="16" /><div>{{ session('error') }}</div></div>
            @endif

            @if ($errors->any())
                <div class="alert error">
                    <x-icon name="alert" :size="16" />
                    <div>
                        @foreach ($errors->all() as $error)
                            <div>{{ $error }}</div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{ $slot ?? '' }}
            @yield('content')
        </main>
    </div>
</div>

{{-- The whole panel script: no bundler, no framework, CSP-friendly. --}}
<script src="{{ asset('assets/atlas.js') }}" defer></script>
@stack('scripts')
</body>
</html>
