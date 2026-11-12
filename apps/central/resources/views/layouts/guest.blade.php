@props(['heading' => null, 'subheading' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $heading ? $heading.' · '.config('app.name') : config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('assets/atlas.css') }}">
    <script src="{{ asset('assets/theme.js') }}"></script>
    <script src="{{ asset('assets/atlas.js') }}" defer></script>
</head>
<body>
    <div class="auth-shell">
        <aside class="auth-aside">
            <a href="{{ route('home') }}" class="site-brand" style="color:#eef5fa">
                <span class="brand-mark">A</span>
                <span>{{ config('app.name') }}</span>
            </a>

            <div style="margin-top:auto">
                <span class="eyebrow" style="color:#7f93a8">Buy licence · Install on your VPS · Own it</span>
                <h2>Your servers.<br>Your licences.<br>One control plane.</h2>
                <p>Purchase a licence here, then install the full admin and hosting panel on any supported VPS with a single command.</p>

                <div class="codeblock" style="border-color:#1e2a36;background:#0a0f14">
                    <span class="comment"># after purchase, on your VPS</span><br>
                    <span class="prompt">$</span> curl -fsSL {{ url('/install.sh') }} -o install.sh<br>
                    <span class="prompt">$</span> sudo bash install.sh --license=<span data-copy-source>ATLS-XXXX-XXXX-XXXX-XXXX</span>
                    <button type="button" class="copy-btn" data-copy="sibling">copy</button>
                </div>
            </div>
        </aside>

        <main class="auth-main">
            <div class="auth-card">
                <a href="{{ route('home') }}" class="site-brand mb-3" style="gap:8px">
                    <span class="brand-mark" style="width:26px;height:26px;font-size:.78rem">A</span>
                    <span style="font-size:.92rem">{{ config('app.name') }}</span>
                </a>

                @if ($heading)
                    <h1>{{ $heading }}</h1>
                @endif
                @if ($subheading)
                    <p>{{ $subheading }}</p>
                @endif

                <div class="auth-form">
                    {{ $slot }}
                </div>

                <div class="auth-foot">
                    Need help? <a href="{{ route('docs') }}" class="link">Read the documentation</a>.
                </div>
            </div>
        </main>
    </div>
</body>
</html>
