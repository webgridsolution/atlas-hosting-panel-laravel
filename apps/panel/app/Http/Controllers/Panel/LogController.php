<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Hosting\FileManager;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * The log reader.
 *
 * cPanel's "Errors" screen tails the account's own error log. This does the same
 * thing with the file manager the panel already has: it lists what is in
 * ~/logs and reads the last few hundred lines of the one asked for.
 *
 * Two rules:
 *
 *  - Only files inside <home>/logs are readable here. The path is built from the
 *    account and the file name, and the name is checked against a strict
 *    pattern, so this cannot become a general file reader with a different URL.
 *  - A line is shown as text and escaped by Blade. A log line is attacker
 *    controlled — it contains whatever a visitor put in a URL — so it is never
 *    rendered as HTML and never treated as a format string.
 */
class LogController extends Controller
{
    use ResolvesAccount;

    /** How many lines from the end are shown. */
    public const TAIL_DISPLAY = 400;

    private const TAIL = self::TAIL_DISPLAY;

    /** Refuse anything above this rather than reading a gigabyte into memory. */
    private const MAX_BYTES = 2097152;

    public function __construct(private readonly FileManager $files) {}

    public function index(Request $request): View
    {
        $account = $this->requireAccount();
        $dir = $account->home().'/logs';

        $files = [];
        $problem = null;

        try {
            foreach ($this->files->list($account, $dir) as $entry) {
                if (($entry['type'] ?? 'file') === 'dir') {
                    continue;
                }

                $name = (string) ($entry['name'] ?? '');

                // Dot-files in a log folder are not logs a customer opens.
                if ($name === '' || str_starts_with($name, '.')) {
                    continue;
                }

                $files[] = [
                    'name' => $name,
                    'size' => (int) ($entry['size'] ?? 0),
                    'modified' => (string) ($entry['mod_time'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            // No logs folder is the normal state for a brand-new account.
            $problem = null;
        }

        usort($files, fn ($a, $b) => strcmp($a['name'], $b['name']));

        $selected = (string) $request->query('file', $files[0]['name'] ?? '');
        $lines = [];
        $truncated = false;
        $error = $problem;

        if ($selected !== '' && ! $this->isSafeName($selected)) {
            $selected = '';
            $error = 'That is not a log in this account.';
        }

        if ($selected !== '') {
            try {
                $file = $this->files->download($account, $dir.'/'.$selected);

                $bytes = $file['bytes'];
                $truncated = strlen($bytes) > self::MAX_BYTES;

                // A log can be a gigabyte. Keep the end of it and drop the rest,
                // so the screen stays bounded no matter what is on disk.
                if ($truncated) {
                    $bytes = substr($bytes, -self::MAX_BYTES);
                }

                $lines = $this->tail($bytes, self::TAIL);
            } catch (Throwable $e) {
                $error = 'That log could not be read: '.$e->getMessage();
            }
        }

        return view('panel.logs.index', [
            'account' => $account,
            'dir' => $dir,
            'files' => $files,
            'selected' => $selected,
            'lines' => $lines,
            'truncated' => $truncated,
            'error' => $error,
        ]);
    }

    /**
     * A log name, not a path. The download call would accept a path, so this is
     * the check that keeps the screen a log reader rather than a file reader.
     */
    private function isSafeName(string $name): bool
    {
        if (str_contains($name, '/') || str_contains($name, "\0")) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $name);
    }

    /**
     * The last N lines of a byte string.
     *
     * Read forwards rather than backwards: a backwards scan is easy to get wrong
     * at a chunk boundary, and the files here are capped at 2 MB in the caller
     * before this ever sees one bigger than that.
     *
     * @return array<int, string>
     */
    private function tail(string $bytes, int $count): array
    {
        if ($bytes === '') {
            return [];
        }

        // Invalid UTF-8 in a log line is normal (a filename in another encoding,
        // a truncated multibyte sequence). Convert rather than drop.
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            $bytes = (string) mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
        }

        $all = preg_split('/\r\n|\n|\r/', $bytes) ?: [];

        // A trailing newline produces one empty element; drop it so the last
        // line shown is a real line.
        if ($all !== [] && end($all) === '') {
            array_pop($all);
        }

        return array_slice($all, -$count);
    }
}
