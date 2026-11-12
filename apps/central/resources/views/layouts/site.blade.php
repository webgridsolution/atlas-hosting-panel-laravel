@props(['title' => null, 'description' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · '.config('app.name') : config('app.name').' — Run your own hosting control panel' }}</title>
    <meta name="description" content="{{ $description ?? 'Atlas is a WHM-style and cPanel-style hosting control panel you install on your own VPS. Buy a licence, run one installer, manage everything.' }}">
    <link rel="stylesheet" href="{{ asset('assets/atlas.css') }}">
    <script src="{{ asset('assets/theme.js') }}"></script>
    <script src="{{ asset('assets/atlas.js') }}" defer></script>
</head>
<body class="site">
    <header class="site-nav">
        <div class="container site-nav-inner">
            <a href="{{ route('home') }}" class="site-brand">
                <span class="brand-mark">A</span>
                <span>{{ config('app.name') }}</span>
            </a>

            <nav class="site-links" aria-label="Primary">
                <a href="{{ route('home') }}#how-it-works">How it works</a>
                <a href="{{ route('pricing') }}">Pricing</a>
                <a href="{{ route('docs') }}">Docs</a>
                <a href="{{ route('legal') }}">Legal</a>
            </nav>

            <div class="site-cta">
                <button type="button" class="btn-icon" data-theme-toggle aria-label="Toggle theme">
                    <x-icon name="moon" :size="18" />
                </button>

                @auth
                    <a href="{{ auth()->user()->isStaff() ? route('admin.dashboard') : route('dashboard') }}" class="btn btn-secondary btn-sm">Console</a>
                @else
                    <a href="{{ route('login') }}" class="btn btn-ghost btn-sm">Sign in</a>
                    <a href="{{ route('register') }}" class="btn btn-primary btn-sm">Get a licence</a>
                @endauth
            </div>
        </div>
    </header>

    <main>
        {{ $slot }}
    </main>

    <footer class="site-footer">
        <div class="container">
            <div class="footer-grid">
                <div>
                    <a href="{{ route('home') }}" class="site-brand mb-2">
                        <span class="brand-mark">A</span>
                        <span>{{ config('app.name') }}</span>
                    </a>
                    <p class="small muted" style="max-width:34ch">
                        A hosting control panel you install on your own VPS. Your server, your data, your licence.
                    </p>
                </div>
                <div>
                    <h4>Product</h4>
                    <a href="{{ route('pricing') }}">Pricing</a>
                    <a href="{{ route('docs') }}#install">Install guide</a>
                    <a href="{{ route('docs') }}#requirements">Requirements</a>
                    <a href="{{ route('docs') }}#compatibility">Compatibility</a>
                </div>
                <div>
                    <h4>Company</h4>
                    <a href="{{ route('legal') }}#terms">Terms</a>
                    <a href="{{ route('legal') }}#privacy">Privacy</a>
                    <a href="{{ route('legal') }}#refunds">Refunds</a>
                </div>
                <div>
                    <h4>Account</h4>
                    <a href="{{ route('login') }}">Sign in</a>
                    <a href="{{ route('register') }}">Create account</a>
                    <a href="{{ route('dashboard') }}">Customer portal</a>
                </div>
            </div>

            <div class="footer-note">
                <span>© {{ date('Y') }} {{ config('app.name') }}. Independent product — not affiliated with cPanel, WHM, WebPros, Plesk or Webuzo.</span>
                <span class="mono">v{{ config('atlas.version') }}</span>
            </div>
        </div>
    </footer>
</body>
</html>
