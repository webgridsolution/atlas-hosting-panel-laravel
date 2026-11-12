@extends('layouts.app')

@php
    $onEmailScreen = request()->routeIs('panel.email.index');
@endphp

@section('title', $onEmailScreen ? 'Email' : 'Webmail')

@section('content')
    <h1>{{ $onEmailScreen ? 'Email' : 'Webmail' }}</h1>

    <div class="card" style="max-width:760px">
        <span class="badge warn">Mail module disabled</span>
        <h2 style="margin-top:14px">This server is not running a mail service</h2>

        <p>
            Mail is an optional module and it is switched off on this server. There is no local IMAP or
            SMTP service for webmail to connect to, so no mailbox login is offered — a form that can
            only fail is worse than no form.
        </p>

        <p class="small muted">
            Most installations that send and receive mail through an external provider (Google
            Workspace, Microsoft 365, a hosted relay) never enable this module. Your existing
            mailboxes and stored messages are untouched while it is off; they appear again, unchanged,
            as soon as it is switched on.
        </p>

        <div class="divider"></div>

        <h3>How the module is enabled</h3>
        <p class="small">
            This is a decision for the server operator, not for a hosting account, because it installs
            system services. It is done at installation time:
        </p>
        <ol class="small">
            <li>Re-run the installer on the VPS <em>without</em> <code class="mono">--skip-mail</code>. It installs Postfix and Dovecot, opens the submission and IMAP ports and writes
                <code class="mono">ATLAS_MAIL_ENABLED=true</code> into the panel environment file.</li>
            <li>The installer restarts the panel and refreshes its configuration cache.</li>
            <li>Publish the MX, SPF, DKIM and DMARC records the panel proposes for each domain.</li>
            @unless ($onEmailScreen)
                <li>Create mailboxes under <a href="{{ route('panel.email.index') }}">Email</a>.</li>
            @endunless
        </ol>

        <p class="small muted" style="margin-bottom:0">
            Current state: <code class="mono">ATLAS_MAIL_ENABLED=false</code> ·
            IMAP {{ config('atlas.mail.imap_host') }}:{{ config('atlas.mail.imap_port') }} ·
            SMTP {{ config('atlas.mail.smtp_host') }}:{{ config('atlas.mail.smtp_port') }}
            (configuration retained, unused while the module is off)
        </p>
    </div>
@endsection
