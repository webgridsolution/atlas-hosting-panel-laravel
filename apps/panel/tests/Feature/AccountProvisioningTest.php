<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Domain;
use App\Models\User;

/**
 * Provisioning is the one place where a web request changes the machine.
 * These tests are about what happens when that fails halfway, and about the
 * promises the panel makes about limits and passwords.
 */
class AccountProvisioningTest extends PanelTestCase
{
    public function test_an_admin_can_create_an_account_end_to_end(): void
    {
        $admin = $this->user('admin');

        $response = $this->actingAs($admin)->post(route('whm.accounts.store'), [
            'username' => 'dhanbad',
            'domain' => 'dhanbadweb.in',
            'package_id' => $this->package()->id,
            'owner_email' => 'owner@dhanbad.in',
            'owner_name' => 'Dhanbad Owner',
        ]);

        $account = Account::where('username', 'dhanbad')->firstOrFail();

        $response->assertRedirect(route('whm.accounts.show', $account));

        $this->assertSame(Account::STATUS_ACTIVE, $account->status);
        $this->assertNotNull($account->provisioned_at);

        // The Linux account was created with the package's limits, not defaults.
        $this->assertTrue($this->agent->called('account.create'));

        $args = $this->agent->argsFor('account.create');
        $this->assertSame('dhanbad', $args['username']);
        $this->assertSame($this->package()->disk_mb, $args['disk_mb']);
        $this->assertSame($this->package()->inodes, $args['inodes']);

        // And a vhost so the site actually serves.
        $this->assertTrue($this->agent->called('vhost.write'));
        $this->assertSame('/home/dhanbad/public_html', $this->agent->argsFor('vhost.write')['document_root']);

        $this->assertDatabaseHas('domains', ['domain' => 'dhanbadweb.in', 'is_primary' => true]);
        $this->assertDatabaseHas('users', ['email' => 'owner@dhanbad.in']);
    }

    public function test_the_owner_password_is_generated_and_shown_once(): void
    {
        $admin = $this->user('admin');

        $response = $this->actingAs($admin)->post(route('whm.accounts.store'), [
            'username' => 'acmecorp',
            'domain' => 'acme.example',
            'package_id' => $this->package()->id,
            'owner_email' => 'boss@acme.example',
        ]);

        $status = session('status');

        $this->assertStringContainsString('Panel login for boss@acme.example:', $status);

        // The password must not be recoverable from the database afterwards.
        $owner = User::where('email', 'boss@acme.example')->firstOrFail();
        $this->assertNotSame('password', $owner->password);

        $response->assertRedirect();

        $this->actingAs($owner)->get(route('panel.dashboard'))->assertOk();
    }

    public function test_a_failed_agent_call_leaves_a_visible_pending_account_not_a_ghost(): void
    {
        $admin = $this->user('admin');
        $this->agent->failOn = 'account.create';

        $response = $this->actingAs($admin)->post(route('whm.accounts.store'), [
            'username' => 'failacct',
            'domain' => 'fail.example',
            'package_id' => $this->package()->id,
        ]);

        $response->assertSessionHas('error');

        $account = Account::where('username', 'failacct')->firstOrFail();

        $this->assertSame(Account::STATUS_PENDING, $account->status);
        $this->assertStringContainsString('provisioning failed', (string) $account->notes);

        // No domain is published, so nothing points at a site that does not exist.
        $this->assertSame(0, Domain::where('account_id', $account->id)->count());
    }

    public function test_a_vhost_failure_also_leaves_the_account_pending(): void
    {
        // The Linux user exists but Apache was not configured. The panel must not
        // claim success: the customer would see a 404 and blame the host.
        $admin = $this->user('admin');
        $this->agent->failOn = 'vhost.write';

        $this->actingAs($admin)->post(route('whm.accounts.store'), [
            'username' => 'halfway',
            'domain' => 'half.example',
            'package_id' => $this->package()->id,
        ])->assertSessionHas('error');

        $this->assertSame(Account::STATUS_PENDING, Account::where('username', 'halfway')->value('status'));
    }

    public function test_reserved_and_invalid_usernames_are_rejected_before_the_agent_is_called(): void
    {
        $admin = $this->user('admin');

        foreach (['root', 'admin', 'atlas', 'AB', 'has_underscore'] as $bad) {
            $this->actingAs($admin)->post(route('whm.accounts.store'), [
                'username' => $bad,
                'domain' => 'x.example',
                'package_id' => $this->package()->id,
            ])->assertSessionHasErrors('username');
        }

        $this->assertFalse($this->agent->called('account.create'));
    }

    public function test_a_duplicate_username_is_a_validation_error_not_a_crash(): void
    {
        $admin = $this->user('admin');
        $this->account(attributes: ['username' => 'taken1']);

        $this->actingAs($admin)->post(route('whm.accounts.store'), [
            'username' => 'taken1',
            'domain' => 'other.example',
            'package_id' => $this->package()->id,
        ])->assertSessionHasErrors('username');
    }

    public function test_suspend_and_unsuspend_tell_the_machine_and_record_it(): void
    {
        $admin = $this->user('admin');
        $account = $this->account();

        $this->actingAs($admin)->post(route('whm.accounts.suspend', $account), ['reason' => 'non-payment'])
            ->assertSessionHas('status');

        $this->assertSame(Account::STATUS_SUSPENDED, $account->fresh()->status);
        $this->assertTrue($this->agent->called('account.suspend'));

        // Suspension is not deletion: the row and the customer's data stay.
        $this->assertDatabaseHas('accounts', ['id' => $account->id]);

        $this->actingAs($admin)->post(route('whm.accounts.unsuspend', $account))->assertSessionHas('status');

        $this->assertSame(Account::STATUS_ACTIVE, $account->fresh()->status);
        $this->assertTrue($this->agent->called('account.unsuspend'));
    }

    public function test_quota_changes_reach_the_agent_and_the_row_together(): void
    {
        $admin = $this->user('admin');
        $account = $this->account();

        $this->actingAs($admin)->patch(route('whm.accounts.quota', $account), [
            'disk_mb' => 2500,
            'inodes' => 50000,
        ])->assertSessionHas('status');

        $this->assertSame(2500, $account->fresh()->disk_mb);

        $args = $this->agent->argsFor('account.quota.set');
        $this->assertSame(2500, $args['disk_mb']);
        $this->assertSame(50000, $args['inodes']);
    }

    public function test_deleting_an_account_keeps_data_unless_it_was_suspended_first(): void
    {
        $admin = $this->user('admin');
        $account = $this->account(attributes: ['status' => Account::STATUS_ACTIVE]);

        // A live account cannot have its data deleted in one click.
        $this->actingAs($admin)->delete(route('whm.accounts.destroy', $account), ['delete_data' => true])
            ->assertSessionHas('error');
        $this->assertDatabaseHas('accounts', ['id' => $account->id]);

        $account->update(['status' => Account::STATUS_SUSPENDED]);

        $this->actingAs($admin)->delete(route('whm.accounts.destroy', $account))->assertSessionHas('status');

        // Soft-deleted: the row is retained so an accidental delete is recoverable,
        // but the account is gone from every screen and from uniqueness checks.
        $this->assertSoftDeleted('accounts', ['id' => $account->id]);
        $this->assertTrue($this->agent->called('account.delete'));

        // keep_data=true is the default: files survive a mistaken delete.
        $this->assertTrue($this->agent->argsFor('account.delete')['keep_data']);
    }

    public function test_a_reseller_provisions_an_account_they_own(): void
    {
        $reseller = $this->user('reseller');

        $this->actingAs($reseller)->post(route('reseller.accounts.store'), [
            'username' => 'firstone',
            'domain' => 'first.example',
            'package_id' => $this->package()->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('accounts', [
            'username' => 'firstone',
            'reseller_id' => $reseller->id,
        ]);

        // And the new account is theirs to see, not the admin's problem to hunt for.
        $this->actingAs($reseller)->get(route('reseller.accounts.index'))->assertSee('firstone');
    }

    public function test_a_reseller_never_gets_admin_powers_by_provisioning(): void
    {
        $reseller = $this->user('reseller');

        $this->actingAs($reseller)->get(route('whm.accounts.index'))->assertForbidden();
    }
}
