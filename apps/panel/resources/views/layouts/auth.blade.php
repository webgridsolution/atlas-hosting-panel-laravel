<!DOCTYPE html>
<html lang="en" data-theme="{{ request()->cookie('atlas-theme', 'dark') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Sign in' }} · Atlas</title>
    <link rel="stylesheet" href="/assets/atlas.css">
</head>
<body>
<div class="auth-wrap">
    <aside class="auth-aside">
        <div class="brand" style="padding-left:0">
            <span class="mark">A</span>
            <span>Atlas<small>Hosting control panel</small></span>
        </div>

        <div>
            <h2>Everything your customers host, in one place.</h2>
            <ul>
                <li>Sites on Apache with an isolated PHP-FPM pool per account</li>
                <li>Databases, mail, DNS, SSL, cron and backups</li>
                <li>Every action runs through a signed, allowlisted agent</li>
            </ul>
        </div>

        <p class="small faint" style="margin:0">
            Ports are configurable; this server uses {{ config('atlas.ports.whm') }} for WHM,
            {{ config('atlas.ports.panel') }} for hosting and {{ config('atlas.ports.webmail') }} for webmail.
        </p>
    </aside>

    <section class="auth-form">
        <div class="auth-card">
            @if (session('status'))
                <div class="alert ok">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="alert error">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            @yield('content')
        </div>
    </section>
</div>
</body>
</html>
