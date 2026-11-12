<?php

namespace Tests\Feature;

use App\Domain\Agent\Agent;
use App\Domain\Agent\AgentException;
use App\Models\Account;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeAgent;

/**
 * An agent that reads a real directory instead of replaying a script.
 *
 * The log reader is the one screen whose whole job is to show bytes off a disk,
 * so testing it against canned strings would prove nothing. This subclass points
 * `file.list` and `file.download` at a temporary home directory the test creates.
 */
class RealFilesAgent extends FakeAgent
{
    public function __construct(public string $root) {}

    public function call(string $op, array $args = []): array
    {
        $path = (string) ($args['path'] ?? '');

        if ($op === 'file.list') {
            $this->calls[] = ['op' => $op, 'args' => $args];

            $entries = [];

            foreach (scandir($path) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $full = rtrim($path, '/').'/'.$name;

                $entries[] = [
                    'name' => $name,
                    'path' => $full,
                    'type' => is_dir($full) ? 'dir' : 'file',
                    'size' => is_dir($full) ? 0 : (int) filesize($full),
                    'mode' => '0644',
                    'mod_time' => date('c', (int) filemtime($full)),
                ];
            }

            return ['path' => $path, 'entries' => $entries];
        }

        if ($op === 'file.download') {
            $this->calls[] = ['op' => $op, 'args' => $args];

            if (! is_file($path)) {
                throw new AgentException('no such file', $op, 404);
            }

            $bytes = (string) file_get_contents($path);

            return [
                'content' => base64_encode($bytes),
                'bytes' => strlen($bytes),
                'truncated' => false,
                'mime_type' => 'text/plain',
            ];
        }

        return parent::call($op, $args);
    }
}

/**
 * The log reader.
 *
 * Two things must hold: it shows the tail of a real log, and it is not a general
 * file reader wearing a different URL. The second is the one worth testing
 * hardest — a path traversal here would read any file the panel user can.
 */
class LogsTest extends PanelTestCase
{
    private User $customer;

    private Account $account;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        // Accounts resolve their home from config, so point that at a temp
        // directory and write real files into it.
        $this->root = sys_get_temp_dir().'/atlas-logs-'.bin2hex(random_bytes(4));

        config(['atlas.defaults.home' => $this->root]);

        $this->app->instance(Agent::class, new RealFilesAgent($this->root));

        $this->customer = $this->user('customer');
        $this->account = $this->account($this->customer);

        $home = $this->account->home();

        @mkdir($home.'/logs', 0755, true);
        @mkdir($home.'/public_html', 0755, true);

        file_put_contents($home.'/logs/error_log', implode("\n", [
            'line one',
            'line two',
            '[Wed Nov 12 09:00:00 2026] [error] [client 1.2.3.4] File does not exist: /public_html/missing.css',
            'last line',
        ])."\n");

        file_put_contents($home.'/logs/access_log', "GET / 200\nGET /about 200\n");

        // Not a log: proves the screen only offers what is in logs/.
        file_put_contents($home.'/public_html/secrets.txt', 'do not show me');
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($it as $file) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }

            @rmdir($this->root);
        }

        parent::tearDown();
    }

    private function screen(array $query = [])
    {
        return $this->actingAs($this->customer)->get(route('panel.logs.index', $query));
    }

    public function test_the_screen_lists_the_logs_and_shows_the_last_one(): void
    {
        $html = $this->screen()->assertOk()->getContent();

        $this->assertStringContainsString('access_log', $html);
        $this->assertStringContainsString('error_log', $html);

        // Logs sort by name, so access_log is the one shown without a choice.
        $this->assertStringContainsString('GET /about 200', $html);
    }

    public function test_a_named_log_can_be_opened(): void
    {
        $html = $this->screen(['file' => 'error_log'])->assertOk()->getContent();

        $this->assertStringContainsString('File does not exist', $html);
        $this->assertStringContainsString('last line', $html);
    }

    public function test_a_log_line_is_escaped_not_rendered(): void
    {
        file_put_contents(
            $this->account->home().'/logs/inject_log',
            "<script>alert('xss')</script>\n<img src=x onerror=alert(1)>\n"
        );

        $html = $this->screen(['file' => 'inject_log'])->assertOk()->getContent();

        $this->assertStringNotContainsString("<script>alert('xss')</script>", $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<img src=x onerror=', $html);
    }

    // -----------------------------------------------------------------------
    // it is a log reader, not a file reader
    // -----------------------------------------------------------------------

    public static function traversalNames(): array
    {
        return [
            'parent directory' => ['../../../../etc/passwd'],
            'sibling folder' => ['../public_html/secrets.txt'],
            'absolute path' => ['/etc/passwd'],
            'null byte' => ["error_log\0.txt"],
            'nested' => ['sub/error_log'],
        ];
    }

    #[DataProvider('traversalNames')]
    public function test_a_path_is_not_a_log_name(string $name): void
    {
        $html = $this->screen(['file' => $name])->assertOk()->getContent();

        $this->assertStringNotContainsString('do not show me', $html);
        $this->assertStringNotContainsString('root:x:0:0', $html);
        $this->assertStringContainsString('not a log in this account', $html);
    }

    public function test_a_log_that_does_not_exist_is_reported_not_fatal(): void
    {
        $html = $this->screen(['file' => 'nope_log'])->assertOk()->getContent();

        $this->assertStringContainsString('nope_log', $html);
        $this->assertStringContainsString('could not be read', $html);
    }

    public function test_an_account_with_an_empty_logs_folder_says_so(): void
    {
        foreach (glob($this->account->home().'/logs/*') ?: [] as $file) {
            @unlink($file);
        }

        $this->screen()
            ->assertOk()
            ->assertSee('No logs yet', escape: false);
    }

    public function test_an_account_whose_logs_folder_does_not_exist_still_renders(): void
    {
        // A brand-new account has no logs folder at all. That is not an error
        // state, so the screen still comes up.
        $bare = $this->account($this->user('customer'));

        @mkdir($bare->home(), 0755, true);

        $this->actingAs($bare->user)
            ->get(route('panel.logs.index'))
            ->assertOk()
            ->assertSee('No logs yet', escape: false);
    }

    // -----------------------------------------------------------------------
    // the tail
    // -----------------------------------------------------------------------

    public function test_a_long_log_is_cut_to_the_last_four_hundred_lines(): void
    {
        $lines = [];
        for ($i = 1; $i <= 600; $i++) {
            $lines[] = 'line '.$i;
        }
        file_put_contents($this->account->home().'/logs/big_log', implode("\n", $lines)."\n");

        $html = $this->screen(['file' => 'big_log'])->assertOk()->getContent();

        $this->assertStringContainsString('line 600', $html);
        $this->assertStringNotContainsString('line 199', $html);
        $this->assertStringContainsString('line 201', $html);
    }

    public function test_the_view_says_how_many_lines_it_shows(): void
    {
        $html = $this->screen()->assertOk()->getContent();

        $this->assertStringContainsString('400', $html);
    }

    // -----------------------------------------------------------------------
    // roles
    // -----------------------------------------------------------------------

    public function test_an_admin_cannot_open_a_customers_log_screen(): void
    {
        // These screens belong to a hosting account; an admin has none, so there
        // is nothing to read and the account resolver refuses.
        $this->actingAs($this->user('admin'))
            ->get(route('panel.logs.index'))
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get(route('panel.logs.index'))->assertRedirect(route('login'));
    }
}
