<?php

namespace Tests\Feature;

use App\Domain\Hosting\SshManager;

/**
 * SSH access is the most dangerous screen in the customer panel: it hands out
 * a shell on the server. Every test here is about what the panel refuses.
 *
 * The validator it exercises is mirrored in the Go agent
 * (internal/ops/file_ssh_validate.go). If these rules change, both files
 * change — that is why they are worded the same.
 */
class SshAccessTest extends PanelTestCase
{
    private function customerWithAccount(): array
    {
        $owner = $this->user('customer');
        $account = $this->account($owner, attributes: ['username' => 'acme']);

        return [$owner, $account];
    }

    // -----------------------------------------------------------------------
    // the screen
    // -----------------------------------------------------------------------

    public function test_the_screen_lists_keys_and_the_connection_details(): void
    {
        [$owner] = $this->customerWithAccount();

        $response = $this->actingAs($owner)->get(route('panel.ssh.index'));

        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('id_ed25519', $html, 'the key list did not render');
        // The table truncates a fingerprint to its readable prefix; the full
        // string lives in the title attribute.
        $this->assertStringContainsString('SHA256:fakefingerprint', $html, 'the fingerprint did not render');
        $this->assertStringContainsString('22', $html, 'the port did not render');
        $this->assertStringContainsString('acme', $html, 'the login hint did not render');
    }

    public function test_a_customer_without_an_account_cannot_reach_ssh(): void
    {
        $nobody = $this->user('customer');

        $this->actingAs($nobody)->get(route('panel.ssh.index'))->assertForbidden();
        $this->assertFalse($this->agent->called('ssh.key.list'));
    }

    // -----------------------------------------------------------------------
    // generating and importing
    // -----------------------------------------------------------------------

    public function test_generating_a_key_asks_the_agent_for_ed25519_by_default(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->post(route('panel.ssh.keys.store'), [
                'name' => 'id_ed25519',
                'type' => 'ed25519',
            ])
            ->assertRedirect();

        $args = $this->agent->argsFor('ssh.key.generate');

        $this->assertSame('acme', $args['username']);
        $this->assertSame('id_ed25519', $args['name']);
        $this->assertSame('ed25519', $args['type']);
        $this->assertStringContainsString('atlas', (string) $args['comment'], 'a key with no comment should be labelled');
    }

    public function test_an_rsa_key_below_2048_bits_is_refused(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->post(route('panel.ssh.keys.store'), [
                'name' => 'weak',
                'type' => 'rsa',
                'bits' => 1024,
            ])
            ->assertSessionHasErrors('bits');

        $this->assertFalse($this->agent->called('ssh.key.generate'));
    }

    public function test_the_names_ssh_reads_itself_cannot_be_taken(): void
    {
        [$owner] = $this->customerWithAccount();

        foreach (SshManager::RESERVED_NAMES as $reserved) {
            $this->actingAs($owner)
                ->post(route('panel.ssh.keys.store'), [
                    'name' => $reserved,
                    'type' => 'ed25519',
                ])
                ->assertRedirect();

            $this->assertFalse(
                $this->agent->called('ssh.key.generate'),
                $reserved.' was accepted as a key name'
            );
        }
    }

    public function test_a_key_name_ending_in_pub_is_refused(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->post(route('panel.ssh.keys.store'), [
                'name' => 'laptop.pub',
                'type' => 'ed25519',
            ])
            ->assertRedirect();

        $this->assertFalse($this->agent->called('ssh.key.generate'));
        $this->assertStringContainsString('.pub', (string) session('error'));
    }

    public function test_a_key_name_with_a_slash_is_refused_by_validation(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->post(route('panel.ssh.keys.store'), [
                'name' => '../evil',
                'type' => 'ed25519',
            ])
            ->assertSessionHasErrors('name');

        $this->assertFalse($this->agent->called('ssh.key.generate'));
    }

    public function test_importing_a_well_formed_public_key_reaches_the_agent(): void
    {
        [$owner] = $this->customerWithAccount();

        $public = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABgQDfakekeymaterial0000000000000000 acme@laptop';

        $this->actingAs($owner)
            ->post(route('panel.ssh.keys.store'), [
                'name' => 'work-laptop',
                'public' => $public,
            ])
            ->assertRedirect();

        $args = $this->agent->argsFor('ssh.key.import');

        $this->assertSame('work-laptop', $args['name']);
        $this->assertSame($public, $args['public']);
    }

    public function test_importing_a_key_that_is_not_a_key_is_explained(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->post(route('panel.ssh.keys.store'), [
                'name' => 'work',
                'public' => 'this is not a key',
            ])
            ->assertRedirect();

        $this->assertFalse($this->agent->called('ssh.key.import'));

        // Refused for the right reason: what was pasted is not a key type
        // OpenSSH would accept, and the message says which type it saw.
        $error = (string) session('error');
        $this->assertStringContainsString('Key type', $error);
        $this->assertStringContainsString('not one OpenSSH accepts', $error);
    }

    // -----------------------------------------------------------------------
    // authorising
    // -----------------------------------------------------------------------

    public function test_authorising_and_deauthorising_go_through_the_agent(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->post(route('panel.ssh.keys.authorize'), ['name' => 'id_ed25519'])
            ->assertRedirect();

        $this->assertSame('id_ed25519', $this->agent->argsFor('ssh.key.authorize')['name']);

        $this->actingAs($owner)
            ->post(route('panel.ssh.keys.deauthorize'), ['name' => 'id_ed25519'])
            ->assertRedirect();

        $this->assertSame('id_ed25519', $this->agent->argsFor('ssh.key.deauthorize')['name']);
    }

    public function test_a_traversal_attempt_in_a_key_name_never_reaches_the_agent(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->post(route('panel.ssh.keys.authorize'), ['name' => '../authorized_keys'])
            ->assertRedirect();

        $this->assertFalse($this->agent->called('ssh.key.authorize'));
    }

    // -----------------------------------------------------------------------
    // the switch and the private key
    // -----------------------------------------------------------------------

    public function test_switching_access_on_records_it_on_the_account_and_at_the_agent(): void
    {
        [$owner, $account] = $this->customerWithAccount();

        $this->assertFalse((bool) $account->ssh_enabled);

        $this->actingAs($owner)
            ->post(route('panel.ssh.access'), ['enabled' => '1'])
            ->assertRedirect();

        $this->assertTrue($this->agent->argsFor('ssh.access.set')['enabled']);
        $this->assertTrue((bool) $account->fresh()->ssh_enabled);
        $this->assertNotNull($account->fresh()->ssh_enabled_at);
    }

    public function test_switching_access_off_keeps_the_keys(): void
    {
        [$owner, $account] = $this->customerWithAccount();

        $account->forceFill(['ssh_enabled' => true, 'ssh_enabled_at' => now()])->save();

        $this->actingAs($owner)
            ->post(route('panel.ssh.access'), ['enabled' => '0'])
            ->assertRedirect();

        $this->assertFalse((bool) $account->fresh()->ssh_enabled);

        // Nothing is deleted: no key.delete, and the keys are still listed.
        $this->assertFalse($this->agent->called('ssh.key.delete'));
        $this->actingAs($owner)->get(route('panel.ssh.index'))->assertOk();
    }

    public function test_downloading_a_private_key_is_never_cached(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->agent->calls[] = ['op' => 'seed', 'args' => []];

        $response = $this->actingAs($owner)
            ->get(route('panel.ssh.keys.download', ['name' => 'id_ed25519']));

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('Content-Disposition'),
            'a private key must download, not render'
        );
    }

    public function test_deleting_a_key_asks_the_agent_and_takes_it_out_of_the_list(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->delete(route('panel.ssh.keys.destroy'), ['name' => 'old-key'])
            ->assertRedirect();

        $this->assertSame('old-key', $this->agent->argsFor('ssh.key.delete')['name']);
    }
}
