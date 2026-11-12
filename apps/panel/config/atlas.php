<?php

/*
|--------------------------------------------------------------------------
| Atlas panel configuration
|--------------------------------------------------------------------------
|
| Everything here is either an environment variable or a value the operator
| changes in WHM. Nothing is hard-coded into a business-logic class, so a
| distribution that ships different defaults changes this file only.
|
*/

return [

    'version' => env('ATLAS_VERSION', '0.9.0'),

    /*
    | What the installer knows about this machine. The public address is written
    | at install time because the panel is reached through a proxy on many VPS
    | images, where the request's own address is the proxy's.
    */
    'server' => [
        'ip' => env('ATLAS_SERVER_IP'),
        'hostname' => env('ATLAS_SERVER_HOSTNAME', gethostname() ?: 'atlas'),
    ],

    /*
    | The privileged Go agent. `socket` is the only driver that touches the
    | machine; `sandbox` writes to storage/app/sandbox and exists so the panel
    | can be evaluated on a laptop. The UI labels sandbox mode on every screen.
    */
    'agent' => [
        'driver' => env('ATLAS_AGENT_DRIVER', 'socket'),
        'socket' => env('ATLAS_AGENT_SOCKET', '/run/atlas/agent.sock'),
        'key' => env('ATLAS_AGENT_KEY', '/etc/atlas/agent.secret'),
        'timeout' => (int) env('ATLAS_AGENT_TIMEOUT', 30),
    ],

    /*
    | The central commercial service. Used for licence activation and the daily
    | heartbeat. If it is unreachable the panel keeps running on the last
    | known-good signed token — an outage here is never an outage for customers.
    */
    'central' => [
        'url' => env('ATLAS_CENTRAL_URL', 'https://api.atlas.example'),
        'server_id' => env('ATLAS_SERVER_ID'),
        'grace_days' => (int) env('ATLAS_LICENSE_GRACE_DAYS', 14),
        // Public keys used to verify signed licence tokens, keyed by kid.
        // Rotation ships a second key; old tokens keep verifying.
        'keys' => json_decode((string) env('ATLAS_LICENSE_KEYS', '{}'), true) ?: [],
    ],

    /*
    | Ports. 2087 / 2083 / 2096 are illustrative conventions, not a protocol;
    | the installer renders the nginx config from whatever the operator chose.
    */
    'ports' => [
        'whm' => (int) env('ATLAS_WHM_PORT', 2087),
        'panel' => (int) env('ATLAS_PANEL_PORT', 2083),
        'webmail' => (int) env('ATLAS_WEBMAIL_PORT', 2096),
    ],

    /*
    | Mail and DNS are on by default because the installer provisions Postfix,
    | Dovecot and PowerDNS as part of the standard stack. They are only switched
    | off for a node that deliberately runs without them (a web-only box); the
    | menus then disappear instead of offering buttons that fail.
    */
    'mail' => [
        'enabled' => (bool) env('ATLAS_MAIL_ENABLED', true),
        'imap_host' => env('ATLAS_IMAP_HOST', '127.0.0.1'),
        'imap_port' => (int) env('ATLAS_IMAP_PORT', 993),
        'smtp_host' => env('ATLAS_SMTP_HOST', '127.0.0.1'),
        'smtp_port' => (int) env('ATLAS_SMTP_PORT', 587),
    ],

    'dns' => [
        'enabled' => (bool) env('ATLAS_DNS_ENABLED', true),
        'nameservers' => array_values(array_filter(
            explode(',', (string) env('ATLAS_NAMESERVERS', 'ns1.example.com,ns2.example.com'))
        )),
    ],

    'backups' => [
        'destination' => env('ATLAS_BACKUP_DIR', '/var/backups/atlas'),
    ],

    /*
    | PHP versions this server runs, and which one new accounts get.
    |
    | The installer writes these after it installs the FPM packages. The panel
    | never guesses: a service screen that offers to restart an FPM pool that is
    | not installed is a button that can only fail, and a package that pins a
    | version nobody runs shows the operator a false picture of the machine.
    |
    | Accepted range is 8.1-8.5 because those are the pools the agent's
    | service.restart allowlist covers.
    */
    'php' => [
        'versions' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ATLAS_PHP_VERSIONS', '8.3'))
        ))),
        'default' => env('ATLAS_PHP_DEFAULT', '8.3'),
    ],

    /*
    | One-click application catalogue.
    |
    | Deliberately compiled in rather than fetched: the Go agent enforces the
    | same list, and neither side may install a slug the other has not heard of.
    | A version bump is a release, not a download at runtime.
    */
    'apps' => [
        'wordpress' => [
            'label' => 'WordPress',
            'version' => '6.7',
            'description' => 'Blog and site builder',
            'php' => '8.3',
        ],
        'joomla' => [
            'label' => 'Joomla',
            'version' => '5.2',
            'description' => 'Content management system',
            'php' => '8.3',
        ],
        'drupal' => [
            'label' => 'Drupal',
            'version' => '11.0',
            'description' => 'Structured content framework',
            'php' => '8.3',
        ],
        'matomo' => [
            'label' => 'Matomo',
            'version' => '5.1',
            'description' => 'Privacy-friendly analytics',
            'php' => '8.3',
        ],
        'nextcloud' => [
            'label' => 'Nextcloud',
            'version' => '30',
            'description' => 'Files, calendar and contacts',
            'php' => '8.3',
        ],
    ],

    'defaults' => [
        'home' => env('ATLAS_HOME_ROOT', '/home'),
    ],

];
