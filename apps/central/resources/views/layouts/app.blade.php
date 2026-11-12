@props(['title' => null, 'crumbs' => []])

@php
    $user = auth()->user();
    $isStaff = $user?->isStaff() ?? false;

    $staffNav = [
        'Control plane' => [
            ['label' => 'Dashboard', 'route' => 'admin.dashboard', 'icon' => 'grid', 'active' => 'admin.dashboard'],
            ['label' => 'Customers', 'route' => 'admin.customers.index', 'icon' => 'users', 'active' => 'admin.customers.*'],
            ['label' => 'Licences', 'route' => 'admin.licenses.index', 'icon' => 'key', 'active' => 'admin.licenses.*'],
            ['label' => 'Plans & pricing', 'route' => 'admin.plans.index', 'icon' => 'box', 'active' => 'admin.plans.*'],
            ['label' => 'Servers', 'route' => 'admin.servers.index', 'icon' => 'server', 'active' => 'admin.servers.*'],
        ],
        'Revenue' => [
            ['label' => 'Orders', 'route' => 'admin.orders.index', 'icon' => 'receipt', 'active' => 'admin.orders.*'],
            ['label' => 'Invoices', 'route' => 'admin.invoices.index', 'icon' => 'receipt', 'active' => 'admin.invoices.*'],
            ['label' => 'Subscriptions', 'route' => 'admin.subscriptions.index', 'icon' => 'refresh', 'active' => 'admin.subscriptions.*'],
            ['label' => 'Coupons', 'route' => 'admin.coupons.index', 'icon' => 'box', 'active' => 'admin.coupons.*'],
        ],
        'Operations' => [
            ['label' => 'Tickets', 'route' => 'admin.tickets.index', 'icon' => 'ticket', 'active' => 'admin.tickets.*'],
            ['label' => 'Releases', 'route' => 'admin.releases.index', 'icon' => 'layers', 'active' => 'admin.releases.*'],
            ['label' => 'Audit & security', 'route' => 'admin.audit.index', 'icon' => 'shield', 'active' => 'admin.audit.*'],
            ['label' => 'Settings', 'route' => 'admin.settings.index', 'icon' => 'settings', 'active' => 'admin.settings.*'],
        ],
    ];

    $customerNav = [
        'Workspace' => [
            ['label' => 'Overview', 'route' => 'dashboard', 'icon' => 'grid', 'active' => 'dashboard'],
            ['label' => 'Licences', 'route' => 'customer.licenses.index', 'icon' => 'key', 'active' => 'customer.licenses.*'],
            ['label' => 'Servers', 'route' => 'customer.servers.index', 'icon' => 'server', 'active' => 'customer.servers.*'],
        ],
        'Billing' => [
            ['label' => 'Invoices', 'route' => 'billing.invoices', 'icon' => 'receipt', 'active' => 'billing.*'],
            ['label' => 'Subscriptions', 'route' => 'billing.subscriptions', 'icon' => 'refresh', 'active' => 'billing.subscriptions'],
            ['label' => 'Support', 'route' => 'support.index', 'icon' => 'ticket', 'active' => 'support.*'],
        ],
        'Resources' => [
            ['label' => 'Downloads', 'route' => 'downloads.index', 'icon' => 'download', 'active' => 'downloads.*'],
            ['label' => 'Install guide', 'route' => 'docs', 'icon' => 'terminal', 'active' => 'docs'],
            ['label' => 'Account settings', 'route' => 'profile.edit', 'icon' => 'user', 'active' => 'profile.*'],
        ],
    ];

    $nav = $isStaff ? $staffNav : $customerNav;
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · '.config('app.name') : config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('assets/atlas.css') }}">
    <script src="{{ asset('assets/theme.js') }}"></script>
    <script src="{{ asset('assets/atlas.js') }}" defer></script>
</head>
<body>
    <div class="shell">
        <aside class="sidebar" id="sidebar">
            <a href="{{ route('home') }}" class="sidebar-brand">
                <span class="brand-mark">A</span>
                <span>
                    <span class="sidebar-brand-name">{{ config('app.name') }}</span>
                    <span class="sidebar-brand-sub">{{ $isStaff ? 'Control plane' : 'Customer portal' }}</span>
                </span>
            </a>

            <nav class="sidebar-nav" aria-label="Main">
                @foreach ($nav as $section => $items)
                    <div class="nav-section">
                        <div class="nav-label">{{ $section }}</div>
                        @foreach ($items as $item)
                            <a href="{{ route($item['route']) }}"
                               class="nav-item {{ request()->routeIs($item['active']) ? 'is-active' : '' }}">
                                <x-icon :name="$item['icon']" :size="16" />
                                <span>{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endforeach

                @if ($isStaff)
                    <div class="nav-section">
                        <div class="nav-label">Customer view</div>
                        <a href="{{ route('dashboard') }}" class="nav-item">
                            <x-icon name="external" :size="16" />
                            <span>My own portal</span>
                        </a>
                    </div>
                @endif
            </nav>

            <div class="sidebar-foot">
                <div class="menu-wrap">
                    <button type="button" class="sidebar-user w-full" style="background:none;border:0;cursor:pointer;color:inherit"
                            data-menu-trigger="user-menu" aria-expanded="false">
                        <span class="avatar">{{ $user?->initials() }}</span>
                        <span style="text-align:left;min-width:0">
                            <span class="sidebar-user-name truncate" style="display:block">{{ $user?->name }}</span>
                            <span class="sidebar-user-role">{{ $user?->primaryRoleLabel() }}</span>
                        </span>
                        <x-icon name="chevron-right" :size="14" style="margin-left:auto;opacity:.5" />
                    </button>

                    <div class="menu" id="user-menu" role="menu">
                        <div class="menu-head">
                            <strong>{{ $user?->name }}</strong>
                            <span>{{ $user?->email }}</span>
                        </div>
                        <a href="{{ route('profile.edit') }}" class="menu-item" role="menuitem">
                            <x-icon name="user" :size="15" /> Account settings
                        </a>
                        <a href="{{ route('docs') }}" class="menu-item" role="menuitem">
                            <x-icon name="book" :size="15" /> Documentation
                        </a>
                        <div class="menu-sep"></div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="menu-item danger" role="menuitem">
                                <x-icon name="logout" :size="15" /> Sign out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </aside>

        <div class="scrim" data-scrim></div>

        <div class="main">
            <header class="topbar">
                <button type="button" class="btn-icon mobile-only" data-sidebar-toggle aria-label="Open navigation">
                    <x-icon name="menu" :size="18" />
                </button>

                <nav class="breadcrumbs" aria-label="Breadcrumb">
                    <a href="{{ $isStaff ? route('admin.dashboard') : route('dashboard') }}">{{ $isStaff ? 'Admin' : 'Portal' }}</a>
                    @foreach ($crumbs as $crumb)
                        <span class="sep">/</span>
                        @if (! empty($crumb['url']))
                            <a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>
                        @else
                            <span class="current">{{ $crumb['label'] }}</span>
                        @endif
                    @endforeach
                </nav>

                <div class="topbar-actions">
                    <span class="env-pill">{{ app()->environment() }}</span>
                    <button type="button" class="btn-icon" data-theme-toggle aria-label="Toggle theme">
                        <x-icon name="moon" :size="17" />
                    </button>
                </div>
            </header>

            <div class="page">
                @include('partials.flash')
                {{ $slot }}
            </div>
        </div>
    </div>
</body>
</html>
