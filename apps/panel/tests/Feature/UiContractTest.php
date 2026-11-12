<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;

/**
 * The contract the interface makes with the operator, checked at the HTML level.
 *
 * Everything here regressed at least once during development: the pagination
 * views defaulted to Laravel's Tailwind markup that no stylesheet in this
 * project can style, optional modules rendered forms that could never work, and
 * the service screen offered to restart units that were not installed. These
 * assertions are cheap and they fail loudly in CI rather than in a customer's
 * browser.
 */
#[Group('ui')]
class UiContractTest extends PanelTestCase
{
    public function test_account_list_uses_the_atlas_pager_not_laravels_tailwind_markup(): void
    {
        $admin = $this->user('admin');

        // 25 accounts at 20 per page: two pages, so the pager must render.
        for ($i = 1; $i <= 25; $i++) {
            $this->account(attributes: ['username' => sprintf('page%03d', $i)]);
        }

        $response = $this->actingAs($admin)->get('/whm/accounts');

        $response->assertOk();
        $response->assertSee('class="pager"', false);
        $response->assertSee('Showing', false);

        // Laravel's default paginator emits Tailwind utility classes and inline
        // SVG arrows. Neither exists in this project's CSS, so both are banned.
        $response->assertDontSee('dark:bg-gray-', false);
        $response->assertDontSee('inline-flex items-center px-4 py-2', false);
        $response->assertDontSee('aria-label="Pagination Navigation"', false);
    }

    public function test_the_second_page_is_reachable_and_numbered(): void
    {
        $admin = $this->user('admin');

        for ($i = 1; $i <= 25; $i++) {
            $this->account(attributes: ['username' => sprintf('page%03d', $i)]);
        }

        $pageTwo = $this->actingAs($admin)->get('/whm/accounts?page=2');

        $pageTwo->assertOk();
        $pageTwo->assertSee('aria-current="true"', false);
        $pageTwo->assertSee('class="pager-btn is-current"', false);
        $pageTwo->assertSee('rel="prev"', false);

        // Page 2 must contain accounts that page 1 does not.
        $pageOne = $this->actingAs($admin)->get('/whm/accounts');
        $this->assertNotSame(
            $pageOne->viewData('accounts')->pluck('id')->all(),
            $pageTwo->viewData('accounts')->pluck('id')->all(),
        );
    }

    public function test_a_single_page_list_renders_no_pager_at_all(): void
    {
        $admin = $this->user('admin');
        $this->account(attributes: ['username' => 'onlyone']);

        $this->actingAs($admin)->get('/whm/accounts')
            ->assertOk()
            ->assertDontSee('class="pager"', false);
    }

    public function test_pagination_keeps_the_query_string(): void
    {
        $admin = $this->user('admin');

        for ($i = 1; $i <= 30; $i++) {
            $this->account(attributes: ['username' => sprintf('qry%03d', $i)]);
        }

        $response = $this->actingAs($admin)->get('/whm/accounts?q=qry&page=2');

        $response->assertOk();
        // Whatever filters a screen supports must survive a page change, or an
        // operator paging through a filtered list loses the filter at page 2 and
        // starts editing the wrong accounts.
        $response->assertSee('q=qry', false);
        $response->assertSee('page=1', false);

        // The filtered list is 30 rows; the pager must not claim the total of
        // the unfiltered table.
        $this->assertSame(30, $response->viewData('accounts')->total());
    }

    public function test_webmail_explains_itself_instead_of_offering_a_form_when_mail_is_off(): void
    {
        config(['atlas.mail.enabled' => false]);
        $customer = $this->user('customer');
        $this->account($customer);

        $this->actingAs($customer)->get('/webmail')
            ->assertOk()
            ->assertSee('Mail module disabled')
            ->assertDontSee('type="password"', false);
    }

    public function test_the_email_screen_shows_the_same_explanation_when_mail_is_off(): void
    {
        config(['atlas.mail.enabled' => false]);
        $customer = $this->user('customer');
        $this->account($customer);

        $this->actingAs($customer)->get('/panel/email')
            ->assertOk()
            ->assertSee('Mail module disabled');
    }

    public function test_every_panel_layout_serves_css_and_js_as_plain_assets(): void
    {
        $admin = $this->user('admin');

        $html = $this->actingAs($admin)->get('/whm')->assertOk()->getContent();

        // No bundler: the stylesheet and script are real files under public/.
        $this->assertStringContainsString('/assets/atlas.css', $html);
        $this->assertStringContainsString('/assets/atlas.js', $html);

        // Nothing may depend on a build manifest that only `npm run build` creates.
        $this->assertStringNotContainsString('build/assets', $html);
        $this->assertStringNotContainsString('@vite', $html);
    }

    /**
     * The build step is not "not used", it is absent.
     *
     * Breeze ships a Vite layout and an Alpine entry point; both were removed when
     * the design system was written by hand. This keeps them removed — a stray
     * `@vite` in one layout is enough to break an install on a server with no
     * Node and no built manifest.
     */
    public function test_the_repository_carries_no_bundler_scaffolding(): void
    {
        $root = base_path();

        foreach (['package.json', 'vite.config.js', 'resources/js', 'resources/css', 'node_modules', 'public/build'] as $path) {
            $this->assertFileDoesNotExist(
                $root.'/'.$path,
                "{$path} exists: this panel is built with plain assets under public/ and nothing else"
            );
        }

        foreach (File::allFiles(resource_path('views')) as $view) {
            $this->assertStringNotContainsString(
                '@vite',
                (string) file_get_contents($view->getPathname()),
                $view->getRelativePathname().' still expects a bundler'
            );
        }
    }

    public function test_no_alpine_directives_survive_in_any_rendered_page(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('customer');
        $this->account($customer);

        $pages = [
            [$admin, '/whm'],
            [$admin, '/whm/accounts'],
            [$admin, '/whm/packages'],
            [$admin, '/whm/settings'],
            [$customer, '/panel'],
            [$customer, '/panel/files'],
            [$customer, '/profile'],
        ];

        foreach ($pages as [$user, $uri]) {
            $html = $this->actingAs($user)->get($uri)->assertOk()->getContent();

            // Alpine attributes are the tell-tale; a page carrying them is
            // broken, because Alpine is not loaded anywhere in this project.
            $this->assertDoesNotMatchRegularExpression('/\sx-(data|show|on|ref|model|bind|text|html)\b/', $html, $uri);
        }
    }

    public function test_the_account_menu_links_only_to_routes_that_exist(): void
    {
        $admin = $this->user('admin');
        $this->account(attributes: ['username' => 'linkcheck']);

        $html = $this->actingAs($admin)->get('/whm/accounts')->assertOk()->getContent();

        preg_match_all('/href="([^"#]+)"/', $html, $matches);

        $checked = 0;
        foreach (array_unique($matches[1]) as $href) {
            $path = parse_url($href, PHP_URL_PATH) ?: '/';
            if (str_starts_with($path, '/assets/') || $path === '/logout') {
                continue;
            }

            $status = $this->actingAs($admin)->get($path)->getStatusCode();
            $this->assertContains($status, [200, 302, 403], "{$path} returned {$status}");
            $checked++;
        }

        $this->assertGreaterThan(5, $checked, 'the navigation should contain more than five links');
    }

    public function test_the_service_screen_offers_only_what_this_server_runs(): void
    {
        $admin = $this->user('admin');

        config([
            'atlas.mail.enabled' => false,
            'atlas.dns.enabled' => false,
            'atlas.php.default' => '8.4',
            'atlas.php.versions' => ['8.4'],
        ]);

        $html = $this->actingAs($admin)->get('/whm/services')->assertOk()->getContent();

        // Installed: web server, database, this panel, one FPM pool.
        $this->assertStringContainsString('/whm/services/apache2/restart', $html);
        $this->assertStringContainsString('/whm/services/mariadb/restart', $html);
        $this->assertStringContainsString('/whm/services/atlas-panel/restart', $html);
        $this->assertStringContainsString('/whm/services/php8.4-fpm/restart', $html);

        // Not installed: modules are off, and the pool name follows the config
        // rather than a hard-coded minor version.
        $this->assertStringNotContainsString('/whm/services/postfix/restart', $html);
        $this->assertStringNotContainsString('/whm/services/dovecot/restart', $html);
        $this->assertStringNotContainsString('/whm/services/pdns/restart', $html);
        $this->assertStringNotContainsString('/whm/services/php8.3-fpm/restart', $html);

        // The agent refuses units outside the hosting stack; the panel must not
        // even offer them.
        $this->assertStringNotContainsString('/whm/services/ssh/restart', $html);
    }

    public function test_restarting_a_unit_that_is_not_offered_is_a_404(): void
    {
        $admin = $this->user('admin');
        config(['atlas.mail.enabled' => false]);

        $this->actingAs($admin)->post('/whm/services/dovecot/restart')->assertNotFound();
    }
}
