<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\DnsZone;
use App\Models\Domain;

/**
 * Every screen the customer panel offers, rendered with an account that
 * actually has data.
 *
 * The distinction matters: a screen that renders for an empty account and
 * whitescreens for a real one is the classic panel bug — a Blade `@foreach` over
 * a null relation only fails once a row exists. Each fixture below therefore
 * creates the rows that screen displays.
 */
class HostingScreensTest extends PanelTestCase
{
    private Account $account;

    private Domain $primary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = $this->account($this->user('customer'));

        $this->primary = $this->account->domains()->create([
            'domain' => 'screens.test',
            'document_root' => $this->account->docRoot(),
            'is_primary' => true,
            'type' => Domain::TYPE_PRIMARY,
        ]);

        $this->populate();
    }

    /**
     * One row in every table the panel screens read, so nothing is empty.
     *
     * Only columns that exist are set: the models are guarded, so an invented
     * attribute would be dropped silently and the fixture would quietly stop
     * testing the screen it was written for.
     */
    private function populate(): void
    {
        $a = $this->account;

        $a->domains()->create([
            'domain' => 'blog.screens.test',
            'document_root' => $a->home().'/blog.screens.test',
            'type' => Domain::TYPE_SUBDOMAIN,
            'parent_id' => $this->primary->id,
            'label' => 'blog',
        ]);

        $a->databases()->create(['name' => 'screens_db', 'db_user' => 'screens_db', 'size_mb' => 12]);

        $a->databaseUsers()->create([
            'username' => 'screens_user', 'privileges' => 'ALL PRIVILEGES',
        ]);

        $a->mailboxes()->create([
            'address' => 'hello@screens.test', 'local_part' => 'hello',
            'domain' => 'screens.test', 'quota_mb' => 1024,
        ]);

        $a->ftpAccounts()->create([
            'username' => 'screens_ftp', 'home_dir' => $a->home(),
            'quota_mb' => 2048, 'status' => 'active',
        ]);

        $a->redirects()->create([
            'domain_id' => $this->primary->id, 'source_path' => '/old',
            'target_url' => 'https://screens.test/new', 'status_code' => 301,
        ]);

        $a->cronJobs()->create([
            'schedule' => '0 3 * * *', 'command' => '/usr/bin/php artisan schedule:run',
            'description' => 'nightly',
        ]);

        $a->backups()->create([
            'kind' => 'full', 'target' => 'home', 'archive' => '/var/backups/atlas/screens.tar.gz',
            'bytes' => 1048576, 'status' => 'complete',
        ]);

        $zone = DnsZone::create(['account_id' => $a->id, 'domain' => 'screens.test', 'status' => 'active']);

        $zone->records()->create(['name' => '@', 'type' => 'A', 'content' => '203.0.113.10', 'ttl' => 3600]);
    }

    /**
     * The full list of GET screens. Kept explicit rather than derived from the
     * router: a route that is added but never listed here is a screen nobody
     * exercised, and the count assertion at the bottom fails so it cannot be
     * forgotten silently.
     */
    public function test_every_customer_screen_renders_with_data(): void
    {
        $routes = [
            'panel.dashboard',
            'panel.domains.index',
            'panel.websites.index',
            'panel.redirects.index',
            'panel.dns.index',
            'panel.databases.index',
            'panel.databases.users',
            'panel.email.index',
            'panel.email.policy',
            'panel.email.filters',
            'panel.files.index',
            'panel.files.edit',
            'panel.files.view',
            'panel.files.search',
            'panel.files.download',
            'panel.ssh.index',
            'panel.ssh.keys.download',
            'panel.ftp.index',
            'panel.backups.index',
            'panel.ssl.index',
            'panel.security.index',
            'panel.security.tokens',
            'panel.config.php',
            'panel.config.mime',
            'panel.config.errors',
            'panel.config.extensions',
            'panel.config.extensions.all',
            'panel.logs.index',
            'panel.security.twofactor',
            'panel.cron.index',
            'panel.apps.index',
            'panel.usage.disk',
            'panel.usage.bandwidth',
            'panel.usage.logins',
            'panel.stats.index',
            'panel.dev.index',
            'profile.edit',
        ];

        $user = $this->account->user;

        foreach ($routes as $name) {
            $params = match ($name) {
                'panel.config.extensions' => [$this->primary->id],
                default => [],
            };

            // the file screens are addressed by a path inside the account
            $params = match ($name) {
                'panel.files.edit', 'panel.files.view', 'panel.files.download' => [
                    'path' => $this->account->docRoot().'/index.php',
                ],
                'panel.ssh.keys.download' => ['name' => 'id_ed25519'],
                default => $params,
            };

            $response = $this->actingAs($user)->get(route($name, $params));

            $response->assertOk();
            $this->assertStringNotContainsString(
                'Whoops', $response->getContent(),
                "{$name} rendered an error page"
            );
        }
    }

    public function test_the_customer_panel_has_exactly_the_screens_this_test_covers(): void
    {
        $this->assertCount(37, $this->panelGetRoutes(), 'a panel screen was added or removed; update this test with it');
    }

    /** @return list<string> */
    private function panelGetRoutes(): array
    {
        $out = [];

        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'panel') && $uri !== 'profile') {
                continue;
            }

            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $out[] = $route->getName() ?? $uri;
        }

        return $out;
    }

    /**
     * Nothing in the sidebar may point at a screen that does not answer. This is
     * the check that catches a menu item left behind after a route was renamed.
     */
    public function test_every_sidebar_link_answers(): void
    {
        $user = $this->account->user;

        $html = $this->actingAs($user)->get(route('panel.dashboard'))->getContent();

        $this->assertMatchesRegularExpression('/<aside class="sidebar"[^>]*>.*<\/aside>/s', $html, 'the sidebar is missing');

        preg_match('/<aside class="sidebar"[^>]*>(.*?)<\/aside>/s', $html, $aside);

        preg_match_all('/href="([^"]+)"/', $aside[1] ?? '', $links);

        $this->assertNotEmpty($links[1], 'the sidebar rendered no links');

        foreach (array_unique($links[1]) as $href) {
            $path = parse_url($href, PHP_URL_PATH);
            $query = parse_url($href, PHP_URL_QUERY);

            $response = $this->actingAs($user)->get($path.($query ? '?'.$query : ''));

            $this->assertSame(
                200, $response->getStatusCode(),
                "the sidebar points at {$href}, which answered {$response->getStatusCode()}"
            );
        }
    }

    public function test_the_account_strip_shows_this_accounts_numbers(): void
    {
        $response = $this->actingAs($this->account->user)->get(route('panel.dashboard'));

        $response->assertOk();
        $response->assertSee('class="infobar"', false);
        $response->assertSee($this->account->username);
        $response->assertSee('screens.test');

        // The strip shows the account's home — where a shell lands — because
        // that is what cPanel and Webuzo both put there. The document root has
        // its own place on the Websites screen.
        $response->assertSee($this->account->home());
    }

    /**
     * The strip is the customer's view of their own account. An admin looking at
     * WHM has no account, so the strip must be absent rather than full of blanks.
     */
    public function test_the_account_strip_is_absent_from_the_admin_console(): void
    {
        $response = $this->actingAs($this->user('admin'))->get(route('whm.dashboard'));

        $response->assertOk();
        $response->assertDontSee('class="infobar"', false);
    }

    public function test_a_suspended_account_still_renders_its_screens(): void
    {
        $this->account->update(['status' => Account::STATUS_SUSPENDED, 'suspended_at' => now()]);

        $this->actingAs($this->account->user)->get(route('panel.dashboard'))->assertOk();
    }

    /**
     * The sidebar is the map of the product, so its shape is asserted rather than
     * eyeballed. The order is part of it: a customer looks for Files under the
     * third heading every time, and a group that drifts is a support ticket.
     */
    public function test_the_sidebar_groups_are_the_ones_the_reference_lists(): void
    {
        $html = $this->actingAs($this->account->user)->get(route('panel.dashboard'))->getContent();

        preg_match_all('/<div class="nav-group">(.*?)<\/div>/s', $html, $m);

        // Dashboard sits above the first heading, as it does in cPanel: it is not
        // part of a group, so the first heading is Websites.
        $this->assertSame([
            'Websites',
            'Files',
            'Email',
            'Databases',
            'SSL & Security',
            'PHP & Software',
            'Performance',
            'Advanced',
            'Account',
        ], array_map(fn ($g) => trim(html_entity_decode($g)), $m[1]), 'the sidebar groups drifted from the reference layout');

        // And the dashboard really is above them all.
        $this->assertLessThan(
            strpos($html, '<div class="nav-group">'),
            strpos($html, route('panel.dashboard')),
            'the dashboard link should come before the first group heading'
        );
    }

    /**
     * Every group must have something in it. An empty heading is worse than no
     * heading: it tells a customer a feature exists and then shows nothing.
     */
    public function test_no_sidebar_group_is_empty(): void
    {
        $html = $this->actingAs($this->account->user)->get(route('panel.dashboard'))->getContent();

        preg_match('/<aside class="sidebar"[^>]*>(.*?)<\/aside>/s', $html, $aside);

        foreach (preg_split('/(?=<div class="nav-group">)/', $aside[1] ?? '') as $chunk) {
            if (! str_contains($chunk, 'nav-group')) {
                continue;
            }

            preg_match('/<div class="nav-group">(.*?)<\/div>/s', $chunk, $name);

            $this->assertMatchesRegularExpression(
                '/<a\s[^>]*href=/', $chunk,
                'the '.trim($name[1] ?? '?').' group has no links'
            );
        }
    }
}
