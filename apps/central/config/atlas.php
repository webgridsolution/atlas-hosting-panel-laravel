<?php

/*
|--------------------------------------------------------------------------
| Atlas product configuration
|--------------------------------------------------------------------------
| Values that belong to the product rather than to the framework. Anything an
| operator may need to change on their own installation is read from the
| environment; nothing here contains a credential or a price.
*/

return [

    /*
    | Where builds are served from. Empty means "this site": the installer
    | script and the customer download endpoint both resolve to the current
    | origin. Set ATLAS_DOWNLOAD_BASE to a CDN origin when artifacts are
    | mirrored, e.g. https://dl.example.net — the database stores only the
    | filename, never an absolute URL that can go stale.
    */
    'downloads' => [
        'base' => env('ATLAS_DOWNLOAD_BASE'),
        'disk' => env('ATLAS_DOWNLOAD_DISK', 'local'),
        'path' => env('ATLAS_DOWNLOAD_PATH', 'downloads'),
    ],

    /*
    | The version this build reports in the footer, /up and the health endpoint.
    */
    'version' => env('ATLAS_VERSION', '0.9.0'),

];
