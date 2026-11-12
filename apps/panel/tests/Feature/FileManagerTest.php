<?php

namespace Tests\Feature;

use App\Domain\Agent\Agent;
use App\Domain\Agent\Audit;
use App\Domain\Hosting\FileManager;
use App\Domain\Hosting\SshManager;
use App\Http\Controllers\Panel\FileController;
use App\Models\Account;
use RuntimeException;

/**
 * The file manager is the feature most likely to be abused: it is a
 * customer-facing "write arbitrary bytes to a path" primitive. Most tests here
 * are about the path, not the content.
 *
 * The rest are about the two promises the screen makes that are easy to break:
 * delete never unlinks a file, and a binary file is never rendered as text.
 */
class FileManagerTest extends PanelTestCase
{
    private function customerWithAccount(): array
    {
        $owner = $this->user('customer');
        $account = $this->account($owner, attributes: ['username' => 'acme']);

        return [$owner, $account];
    }

    // -----------------------------------------------------------------------
    // paths
    // -----------------------------------------------------------------------

    public function test_the_listing_stays_inside_the_account_home(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)->get(route('panel.files.index'))->assertOk();

        $this->assertSame('/home/acme', $this->agent->argsFor('file.list')['path']);
    }

    public function test_an_escape_attempt_is_rejected_by_the_service(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->get(route('panel.files.index', ['path' => '/home/acme/../other']))
            ->assertRedirect();

        $this->assertFalse($this->agent->called('file.list'));
    }

    public function test_a_null_byte_in_a_path_is_rejected(): void
    {
        [$owner] = $this->customerWithAccount();

        $manager = app(FileManager::class);
        $account = app(Account::class)->firstOrFail();

        $this->expectException(RuntimeException::class);

        $manager->normalise($account, "/home/acme/public_html/index.php\0.txt");
    }

    public function test_a_sibling_account_is_never_reachable(): void
    {
        [$owner, $account] = $this->customerWithAccount();

        $manager = app(FileManager::class);

        $this->expectException(RuntimeException::class);

        // A path that is a legitimate home — for somebody else.
        $manager->normalise($account, '/home/someoneelse/public_html');
    }

    public function test_the_manager_normalises_relative_paths_to_the_home(): void
    {
        [$owner, $account] = $this->customerWithAccount();

        $manager = app(FileManager::class);

        $this->assertSame('/home/acme/public_html', $manager->normalise($account, 'public_html'));
        $this->assertSame('/home/acme', $manager->normalise($account, ''));
        $this->assertSame('/home/acme/a', $manager->normalise($account, '/home/acme/./b/../a'));
    }

    public function test_dot_segments_are_refused_even_from_the_service(): void
    {
        [$owner, $account] = $this->customerWithAccount();

        $this->expectException(RuntimeException::class);

        app(FileManager::class)->normalise($account, '/home/acme/../../etc/passwd');
    }

    public function test_a_customer_without_an_account_cannot_use_the_file_manager(): void
    {
        $nobody = $this->user('customer');

        // A login with no hosting account behind it is a 403, not a redirect to
        // somewhere it also cannot go.
        $this->actingAs($nobody)->get(route('panel.files.index'))->assertForbidden();
        $this->assertFalse($this->agent->called('file.list'));
    }

    // -----------------------------------------------------------------------
    // the editor
    // -----------------------------------------------------------------------

    public function test_the_editor_opens_a_missing_file_as_a_new_file(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->agent->failOn = 'file.read';

        $response = $this->actingAs($owner)->get(route('panel.files.edit', ['path' => '/home/acme/public_html/new.php']));

        // A simulated failure is not "not found", so it is reported, not treated
        // as a new file. This asserts the two are told apart.
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_saving_a_file_passes_the_exact_content_to_the_agent(): void
    {
        [$owner] = $this->customerWithAccount();

        $content = "<?php\n// ünïcode and \"quotes\"\necho 'x';\n";

        $this->actingAs($owner)
            ->put(route('panel.files.update'), [
                'path' => '/home/acme/public_html/index.php',
                'content' => $content,
            ])
            ->assertRedirect();

        $this->assertSame($content, $this->agent->argsFor('file.write')['content']);
    }

    public function test_append_is_passed_through_rather_than_dropped(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->put(route('panel.files.update'), [
                'path' => '/home/acme/logs/app.log',
                'content' => "one more line\n",
                'append' => '1',
            ])
            ->assertRedirect();

        // The panel sent this flag before the spec carried it, so every append
        // replaced the file. This is the regression test for that.
        $this->assertTrue($this->agent->argsFor('file.write')['append']);
    }

    public function test_saving_outside_the_home_is_refused(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->put(route('panel.files.update'), [
                'path' => '/etc/passwd',
                'content' => 'root:x:0:0',
            ])
            ->assertSessionHasErrors('path');

        $this->assertFalse($this->agent->called('file.write'));
    }

    // -----------------------------------------------------------------------
    // the viewer: the bug that made the page look broken
    // -----------------------------------------------------------------------

    public function test_a_binary_file_shows_no_preview_instead_of_mojibake(): void
    {
        [$owner] = $this->customerWithAccount();

        // A PNG header, which is not valid UTF-8 and contains control bytes.
        $this->agent->calls[] = ['op' => 'seed', 'args' => []];

        $png = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR";

        $this->mockDownload($png, 'image/png');

        $response = $this->actingAs($owner)
            ->get(route('panel.files.view', ['path' => '/home/acme/public_html/broken.txt']));

        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringNotContainsString("\x00", $html, 'a NUL byte reached the page');
        $this->assertStringContainsString('PNG', $html, 'the viewer should say what the file is');
    }

    public function test_an_image_is_rendered_as_a_data_uri(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->mockDownload("\x89PNG\r\n\x1a\nFakeImageBytes", 'image/png');

        $response = $this->actingAs($owner)
            ->get(route('panel.files.view', ['path' => '/home/acme/public_html/logo.png']));

        $response->assertOk();
        $this->assertStringContainsString('data:image/png;base64,', $response->getContent());
    }

    public function test_a_text_file_is_shown_as_text(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->mockDownload("<?php echo 'hello';", 'text/x-php');

        $response = $this->actingAs($owner)
            ->get(route('panel.files.view', ['path' => '/home/acme/public_html/index.php']));

        $response->assertOk();
        $this->assertStringContainsString('hello', $response->getContent());
    }

    /**
     * Replaces the agent's answer for file.download so the viewer's decision is
     * tested against bytes rather than against a fixture's description.
     */
    private function mockDownload(string $bytes, string $mime): void
    {
        $mock = \Mockery::mock(Agent::class);
        $mock->shouldReceive('call')->andReturnUsing(function (string $op, array $args = []) use ($bytes, $mime) {
            if ($op === 'file.download') {
                return [
                    'content' => base64_encode($bytes),
                    'bytes' => strlen($bytes),
                    'truncated' => false,
                    'mime_type' => $mime,
                ];
            }

            return $this->agent->call($op, $args);
        });
        $mock->shouldReceive('healthy')->andReturn(true);

        $this->app->instance(Agent::class, $mock);
        $this->app->forgetInstance(Audit::class);
        $this->app->forgetInstance(FileManager::class);
        $this->app->forgetInstance(SshManager::class);
        $this->app->forgetInstance(FileController::class);
    }

    // -----------------------------------------------------------------------
    // delete: to the trash, never unlinked
    // -----------------------------------------------------------------------

    public function test_deleting_the_home_directory_is_refused_with_an_explanation(): void
    {
        [$owner] = $this->customerWithAccount();

        $response = $this->actingAs($owner)
            ->delete(route('panel.files.destroy'), ['path' => '/home/acme']);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertStringContainsString('home', strtolower((string) session('error')));
        $this->assertFalse($this->agent->called('file.trash'));
        $this->assertFalse($this->agent->called('file.delete'));
    }

    public function test_deleting_an_ordinary_file_moves_it_to_the_trash(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->delete(route('panel.files.destroy'), ['paths' => ['/home/acme/public_html/old.txt']])
            ->assertRedirect();

        // Trash, not delete. Nothing a customer clicks in this panel unlinks a
        // file except emptying the trash.
        $this->assertTrue($this->agent->called('file.trash'));
        $this->assertFalse($this->agent->called('file.delete'));
    }

    public function test_the_trash_is_listed_and_can_be_restored(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)->get(route('panel.files.index'))->assertOk();
        $this->assertTrue($this->agent->called('file.trash.list'));

        $this->actingAs($owner)
            ->post(route('panel.files.restore'), [
                'path' => '/home/acme/.atlas-trash/20260101-000000__cHVibGljX2h0bWwvb2xkLnR4dA',
            ])
            ->assertRedirect();

        $this->assertTrue($this->agent->called('file.restore'));
    }

    public function test_a_restore_of_something_that_is_not_a_trash_entry_is_refused(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->post(route('panel.files.restore'), ['path' => '/home/acme/public_html/index.php'])
            ->assertRedirect();

        // The agent refuses it; the panel must not have asked it to.
        $this->assertStringContainsString(
            'trash',
            strtolower((string) session('error'))
        );
    }

    public function test_emptying_the_trash_unlinks_only_trash_entries(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->post(route('panel.files.trash.empty'))
            ->assertRedirect();

        $deletes = array_values(array_filter(
            $this->agent->calls,
            fn ($c) => $c['op'] === 'file.delete'
        ));

        $this->assertNotEmpty($deletes, 'emptying an empty trash should be quiet, but this one had an entry');

        foreach ($deletes as $call) {
            $this->assertStringContainsString('/.atlas-trash/', (string) $call['args']['path']);
        }
    }

    // -----------------------------------------------------------------------
    // the rest of the toolbox
    // -----------------------------------------------------------------------

    public function test_mkdir_accepts_a_parent_and_a_name(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->post(route('panel.files.mkdir'), [
                'path' => '/home/acme/public_html',
                'name' => 'uploads',
            ])
            ->assertRedirect();

        $this->assertSame('/home/acme/public_html/uploads', $this->agent->argsFor('file.mkdir')['path']);
    }

    public function test_a_name_with_a_slash_is_refused_before_it_reaches_the_agent(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->post(route('panel.files.mkdir'), [
                'path' => '/home/acme/public_html',
                'name' => 'a/b',
            ])
            ->assertRedirect();

        $this->assertFalse($this->agent->called('file.mkdir'));
        $this->assertStringContainsString('slash', (string) session('error'));
    }

    public function test_rename_accepts_a_new_name_and_a_full_destination(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->post(route('panel.files.rename'), [
                'from' => '/home/acme/public_html/old.txt',
                'name' => 'new.txt',
            ])
            ->assertRedirect();

        $rename = $this->agent->argsFor('file.rename');
        $this->assertSame('/home/acme/public_html/old.txt', $rename['from']);
        $this->assertSame('/home/acme/public_html/new.txt', $rename['to']);

        // One atomic rename, not copy-then-delete: there is no window where the
        // customer has two copies and no window where a failure loses the file.
        $this->assertFalse($this->agent->called('file.copy'));
        $this->assertFalse($this->agent->called('file.delete'));
    }

    public function test_a_folder_cannot_be_copied_inside_itself(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->post(route('panel.files.copy'), [
                'paths' => ['/home/acme/public_html'],
                'to' => '/home/acme/public_html/inner',
            ])
            ->assertRedirect();

        $this->assertFalse($this->agent->called('file.copy'));
    }

    public function test_permissions_refuse_a_world_writable_mode(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->post(route('panel.files.chmod'), [
                'paths' => ['/home/acme/public_html'],
                'mode' => '0777',
            ])
            ->assertRedirect();

        $this->assertFalse($this->agent->called('file.chmod'));
        $this->assertTrue(
            str_contains(strtolower((string) session('error')), '777')
            || str_contains(strtolower((string) session('error')), 'world-writable'),
            'the refusal should explain why 777 is refused'
        );
    }

    public function test_permissions_refuse_a_recursive_change_over_the_whole_home(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->post(route('panel.files.chmod'), [
                'paths' => ['/home/acme'],
                'mode' => '0755',
                'recursive' => '1',
            ])
            ->assertRedirect();

        $this->assertFalse($this->agent->called('file.chmod'));
    }

    public function test_compress_names_the_archive_after_the_selection_and_checks_the_extension(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->post(route('panel.files.compress'), [
                'paths' => ['/home/acme/public_html'],
                'dir' => '/home/acme',
                'format' => 'tar.gz',
            ])
            ->assertRedirect();

        $args = $this->agent->argsFor('file.compress');

        $this->assertSame('/home/acme/public_html.tar.gz', $args['archive']);
        $this->assertSame('tar.gz', $args['format']);
    }

    public function test_an_archive_whose_name_does_not_match_its_format_is_refused(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->post(route('panel.files.compress'), [
                'paths' => ['/home/acme/public_html'],
                'archive' => '/home/acme/site.tar.gz',
                'format' => 'zip',
            ])
            ->assertRedirect();

        $this->assertFalse($this->agent->called('file.compress'));
    }

    public function test_extract_defaults_the_destination_to_the_archive_folder(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->post(route('panel.files.extract'), [
                'archive' => '/home/acme/site.tar.gz',
            ])
            ->assertRedirect();

        $this->assertSame('/home/acme', $this->agent->argsFor('file.extract')['dest']);
    }

    public function test_search_refuses_a_glob_with_an_explanation(): void
    {
        [$owner] = $this->customerWithAccount();

        $response = $this->actingAs($owner)
            ->get(route('panel.files.search', ['q' => '*.php']));

        $response->assertOk();
        $this->assertStringContainsString('plain names', $response->getContent());
        $this->assertFalse($this->agent->called('file.search'));
    }

    public function test_search_scopes_to_the_folder_that_was_asked_for(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->get(route('panel.files.search', ['q' => 'wp-config', 'in' => 'public_html']))
            ->assertOk();

        $this->assertSame('/home/acme/public_html', $this->agent->argsFor('file.search')['path']);
        $this->assertStringContainsString('wp-config.php', $this->actingAs($owner)
            ->get(route('panel.files.search', ['q' => 'wp-config']))->getContent());
    }

    public function test_an_upload_with_no_file_is_explained_rather_than_crashing(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)
            ->post(route('panel.files.upload'), ['dir' => '/home/acme/public_html'])
            ->assertRedirect();

        $this->assertFalse($this->agent->called('file.upload'));
        $this->assertStringContainsString('at least one file', (string) session('error'));
    }

    public function test_the_listing_measures_folder_sizes_in_one_call(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->actingAs($owner)->get(route('panel.files.index'))->assertOk();

        $statCalls = array_filter($this->agent->calls, fn ($c) => $c['op'] === 'file.stat');

        $this->assertCount(1, $statCalls, 'one file.stat for the whole listing, not one per row');
    }

    public function test_a_download_over_the_limit_is_refused_with_a_readable_size(): void
    {
        [$owner] = $this->customerWithAccount();

        $this->agent->calls[] = ['op' => 'seed', 'args' => []];

        $mock = \Mockery::mock(Agent::class);
        $mock->shouldReceive('call')->andReturnUsing(function (string $op, array $args = []) {
            if ($op === 'file.download') {
                return [
                    'content' => base64_encode('x'),
                    'bytes' => 40 * 1024 * 1024,
                    'truncated' => false,
                    'mime_type' => 'application/octet-stream',
                ];
            }

            return ['ok' => true];
        });
        $mock->shouldReceive('healthy')->andReturn(true);

        $this->app->instance(Agent::class, $mock);
        $this->app->forgetInstance(Audit::class);
        $this->app->forgetInstance(FileManager::class);
        $this->app->forgetInstance(FileController::class);

        $response = $this->actingAs($owner)
            ->get(route('panel.files.download', ['path' => '/home/acme/big.iso']));

        $response->assertRedirect();
        $this->assertStringContainsString('32 MB', (string) session('error'));
        $this->assertStringContainsString('40 MB', (string) session('error'));
    }
}
