<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Domain;
use App\Models\HostingDatabase;

/**
 * The single most dangerous class of bug in a shared host: customer A reading
 * or changing customer B's things. Every URL that takes an id is tested with
 * the wrong customer's session, not just the right one.
 */
class TenantIsolationTest extends PanelTestCase
{
    private function twoTenants(): array
    {
        $ownerA = $this->user('customer');
        $ownerB = $this->user('customer');

        $a = $this->account($ownerA, attributes: ['username' => 'acme', 'domain' => 'acme.test']);
        $b = $this->account($ownerB, attributes: ['username' => 'sunny', 'domain' => 'sunrise.test']);

        Domain::create(['account_id' => $a->id, 'domain' => 'acme.test', 'document_root' => '/home/acme/public_html', 'is_primary' => true]);
        Domain::create(['account_id' => $b->id, 'domain' => 'sunrise.test', 'document_root' => '/home/sunny/public_html', 'is_primary' => true]);

        return [$ownerA, $ownerB, $a, $b];
    }

    public function test_a_customer_cannot_open_another_customers_website_screen(): void
    {
        [$ownerA, , $a, $b] = $this->twoTenants();

        // The panel screens take a domain, not an id, so the test is that the
        // other customer's domain is not even offered and cannot be acted on.
        $this->actingAs($ownerA)->get(route('panel.websites.index'))
            ->assertOk()
            ->assertSee('acme.test')
            ->assertDontSee('sunrise.test');

        // The route looks the domain up inside the customer's own accounts, so a
        // foreign domain is simply not found — there is nothing to leak.
        $this->actingAs($ownerA)->delete(route('panel.websites.destroy', 'sunrise.test'))
            ->assertNotFound();

        $this->assertSame(1, $b->domains()->where('domain', 'sunrise.test')->count());
        $this->assertFalse($this->agent->called('vhost.remove'));
    }

    public function test_a_customer_cannot_delete_another_customers_database(): void
    {
        [$ownerA, $ownerB, $a, $b] = $this->twoTenants();

        $db = HostingDatabase::create([
            'account_id' => $b->id,
            'name' => 'sunny_wp',
            'db_user' => 'sunny_wp',
            'password_enc' => encrypt('secret-password-123'),
            'status' => 'active',
        ]);

        $this->actingAs($ownerA)
            ->delete(route('panel.databases.destroy', $db))
            ->assertForbidden();

        $this->assertNull($db->fresh()->deleted_at);
        $this->assertFalse($this->agent->called('db.drop'));
    }

    public function test_a_customer_without_an_account_sees_the_no_account_screen_not_an_error(): void
    {
        $orphan = $this->user('customer');

        $this->actingAs($orphan)->get(route('panel.dashboard'))->assertOk()->assertSee('No hosting account');
    }

    public function test_an_admin_and_a_reseller_cannot_use_the_customer_panel(): void
    {
        $this->actingAs($this->user('admin'))->get(route('panel.dashboard'))->assertForbidden();
        $this->actingAs($this->user('reseller'))->get(route('panel.dashboard'))->assertForbidden();
    }

    public function test_a_customer_cannot_reach_admin_or_reseller_screens(): void
    {
        $customer = $this->user('customer');

        foreach (['whm.dashboard', 'whm.accounts.index', 'whm.settings.index', 'reseller.dashboard', 'reseller.accounts.index'] as $route) {
            $this->actingAs($customer)->get(route($route))->assertForbidden();
        }
    }

    public function test_a_reseller_cannot_open_a_customer_they_do_not_own(): void
    {
        $reseller = $this->user('reseller');
        $otherReseller = $this->user('reseller');

        $mine = $this->account(null, $reseller);
        $theirs = $this->account(null, $otherReseller);

        $this->actingAs($reseller)->get(route('reseller.accounts.show', $mine))->assertOk();
        $this->actingAs($reseller)->get(route('reseller.accounts.show', $theirs))->assertForbidden();

        $this->actingAs($reseller)->post(route('reseller.accounts.suspend', $theirs))->assertForbidden();
        $this->assertSame(Account::STATUS_ACTIVE, $theirs->fresh()->status);
    }

    public function test_a_suspended_customer_cannot_log_in_to_their_panel(): void
    {
        [$ownerA, , $a] = $this->twoTenants();

        $a->update(['status' => Account::STATUS_SUSPENDED]);

        $this->actingAs($ownerA)->get(route('panel.dashboard'))->assertOk()->assertSee('suspended');
    }

    public function test_file_paths_from_one_account_cannot_be_used_by_another(): void
    {
        [$ownerA, , $a] = $this->twoTenants();

        // The panel derives the account from the session, not from the request,
        // so asking for another home directory must be refused by the service.
        $this->actingAs($ownerA)
            ->get(route('panel.files.index', ['path' => '/home/sunny/public_html']))
            ->assertRedirect();
    }
}
