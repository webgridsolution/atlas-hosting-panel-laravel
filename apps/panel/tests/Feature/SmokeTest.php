<?php

namespace Tests\Feature;

use App\Models\Mailbox;

/**
 * Every screen, for every role. This is the test that catches a broken Blade
 * include, a missing view variable or a route that only worked because the
 * developer's demo account happened to have a domain.
 */
class SmokeTest extends PanelTestCase
{
    public function test_every_admin_screen_renders(): void
    {
        $admin = $this->user('admin');
        $this->account($this->user('customer'));

        $routes = [
            'whm.dashboard', 'whm.accounts.index', 'whm.accounts.create',
            'whm.packages.index', 'whm.services.index', 'whm.audit.index',
            'whm.licence.index', 'whm.settings.index',
        ];

        foreach ($routes as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
        }
    }

    public function test_every_customer_screen_renders(): void
    {
        $owner = $this->user('customer');
        $account = $this->account($owner);

        $account->domains()->create([
            'domain' => 'smoke.test', 'document_root' => $account->docRoot(), 'is_primary' => true,
        ]);

        $routes = [
            'panel.dashboard', 'panel.websites.index', 'panel.databases.index',
            'panel.email.index', 'panel.dns.index', 'panel.cron.index',
            'panel.backups.index', 'panel.files.index', 'panel.stats.index',
        ];

        foreach ($routes as $route) {
            $this->actingAs($owner)->get(route($route))->assertOk();
        }
    }

    public function test_every_reseller_screen_renders(): void
    {
        $reseller = $this->user('reseller');
        $this->account(null, $reseller);

        foreach (['reseller.dashboard', 'reseller.accounts.index', 'reseller.accounts.create'] as $route) {
            $this->actingAs($reseller)->get(route($route))->assertOk();
        }
    }

    public function test_the_account_detail_screen_renders_for_an_account_with_data(): void
    {
        $admin = $this->user('admin');
        $owner = $this->user('customer');
        $account = $this->account($owner);

        $account->domains()->create(['domain' => 'detail.test', 'document_root' => $account->docRoot(), 'is_primary' => true]);
        $account->databases()->create([
            'name' => 'detail_db', 'db_user' => 'detail_db',
            'password_enc' => encrypt('a-very-long-password'), 'status' => 'active',
        ]);
        $account->mailboxes()->create([
            'address' => 'hello@detail.test', 'local_part' => 'hello', 'domain' => 'detail.test',
            'password_enc' => encrypt('a-very-long-password'), 'quota_mb' => 1024, 'status' => 'active',
        ]);

        $this->actingAs($admin)->get(route('whm.accounts.show', $account))->assertOk();
        $this->actingAs($owner)->get(route('panel.dashboard'))->assertOk();
    }

    public function test_health_endpoint_reports_the_agent_and_the_licence(): void
    {
        $this->agent->healthy = true;

        $this->getJson(route('health'))
            ->assertOk()
            ->assertJsonStructure(['ok', 'agent', 'licence', 'version'])
            ->assertJson(['agent' => 'up', 'licence' => 'active']);
    }

    public function test_health_is_false_when_the_agent_is_down(): void
    {
        $this->agent->healthy = false;

        $this->getJson(route('health'))
            ->assertStatus(503)
            ->assertJson(['ok' => false, 'agent' => 'down']);
    }

    public function test_login_page_is_reachable_and_there_is_no_registration(): void
    {
        $this->get(route('login'))->assertOk();
        $this->get('/register')->assertNotFound();
    }

    public function test_a_bad_password_is_rejected_without_revealing_which_part_was_wrong(): void
    {
        $this->user('admin', ['email' => 'admin@smoke.test']);

        $this->post(route('login'), ['email' => 'admin@smoke.test', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_inactive_user_cannot_sign_in(): void
    {
        $user = $this->user('admin', ['email' => 'disabled@smoke.test', 'is_active' => false]);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_webmail_inbox_requires_a_mailbox_session(): void
    {
        $owner = $this->user('customer');
        $account = $this->account($owner);

        $account->mailboxes()->create([
            'address' => 'hello@webmail.test', 'local_part' => 'hello', 'domain' => 'webmail.test',
            'password_enc' => encrypt('a-very-long-password'), 'quota_mb' => 1024, 'status' => 'active',
        ]);

        // No mailbox session yet: the compose screen sends you to the picker
        // rather than rendering a form that cannot send.
        $this->actingAs($owner)->get(route('webmail.compose'))->assertRedirect();
        // Without a mailbox password the screen is the "choose a mailbox"
        // login, not a broken inbox.
        $this->actingAs($owner)->get(route('webmail.inbox'))->assertOk()->assertSee('hello@webmail.test');
    }

    public function test_the_guest_pages_do_not_leak_the_panel_shell(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('whm.dashboard')
            ->assertSee('Sign in');
    }
}
