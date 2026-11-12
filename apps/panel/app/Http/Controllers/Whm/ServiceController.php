<?php

namespace App\Http\Controllers\Whm;

use App\Domain\Agent\Audit;
use App\Domain\Hosting\Metrics;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Service control for the hosting stack.
 *
 * The list is computed, never fixed. Two rules decided that design:
 *
 *   1. A button that cannot work is a defect. Postfix and Dovecot do not exist
 *      when the mail module is off; PowerDNS does not exist when DNS is off;
 *      the FPM pool name depends on the PHP version the installer actually
 *      installed. A hard-coded list offered all of them on every machine.
 *   2. The panel must not be able to restart anything outside the hosting
 *      stack. The agent enforces its own allowlist — this list is the UI half of
 *      the same rule, so the screen cannot even offer `ssh`.
 */
class ServiceController extends Controller
{
    public function __construct(
        private readonly Metrics $metrics,
        private readonly Audit $audit,
    ) {}

    /**
     * Unit name => human label, filtered by what this installation runs.
     *
     * @return array<string, string>
     */
    public function units(): array
    {
        $units = [
            'apache2' => 'Apache — customer websites',
            'nginx' => 'nginx — panel and TLS termination',
            'mariadb' => 'MariaDB — databases',
        ];

        foreach ($this->phpVersions() as $version) {
            $units["php{$version}-fpm"] = "PHP {$version} FPM";
        }

        if (config('atlas.mail.enabled')) {
            $units['postfix'] = 'Postfix — outbound mail';
            $units['dovecot'] = 'Dovecot — IMAP/POP3';
        }

        if (config('atlas.dns.enabled')) {
            $units['pdns'] = 'PowerDNS — authoritative DNS';
        }

        $units['atlas-panel'] = 'Atlas panel (this application)';

        return $units;
    }

    public function index(): View
    {
        $stats = $this->metrics->server();
        $states = collect($stats['services'] ?? [])->keyBy('unit');

        return view('whm.services.index', [
            'units' => $this->units(),
            'states' => $states,
        ]);
    }

    public function restart(Request $request, string $unit): RedirectResponse
    {
        if (! array_key_exists($unit, $this->units())) {
            abort(404);
        }

        try {
            $this->audit->dispatch('service.restart', ['unit' => $unit]);
        } catch (Throwable $e) {
            return back()->with('error', "Could not restart {$unit}: ".$e->getMessage());
        }

        // A restart invalidates cached stats, which would otherwise claim the
        // service is still in its old state.
        cache()->forget('metrics:server');

        return back()->with('status', "{$unit} restarted.");
    }

    /** @return list<string> */
    private function phpVersions(): array
    {
        $configured = (array) config('atlas.php.versions', ['8.3']);

        $valid = array_values(array_filter(
            $configured,
            fn ($version) => preg_match('/^8\.[1-5]$/', (string) $version) === 1,
        ));

        // The default must always be restartable, even if the operator only
        // listed additional versions in ATLAS_PHP_VERSIONS.
        $default = (string) config('atlas.php.default', '8.3');
        if (preg_match('/^8\.[1-5]$/', $default) === 1 && ! in_array($default, $valid, true)) {
            array_unshift($valid, $default);
        }

        return $valid === [] ? ['8.3'] : $valid;
    }
}
