<?php

namespace App\Http\Controllers\Panel;

use App\Domain\Hosting\FileManager;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\ResolvesAccount;
use App\Http\Requests\UpdateFileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The file manager.
 *
 * Seventeen actions, all of them going through FileManager so the path is
 * normalised twice — once here for a readable error, once in the agent, which
 * is the only thing that actually touches the disk.
 *
 * Two rules the code below keeps to:
 *
 *  - Nothing here deletes a file. Delete moves to the trash; emptying the trash
 *    is a separate action with its own confirmation, and it asks the agent to
 *    unlink, because the panel does not have (and must not want) that power.
 *  - A read that is not text returns null content, not mojibake. A PNG rendered
 *    as ISO-8859-1 is how a panel looks broken when nothing is wrong.
 */
class FileController extends Controller
{
    use ResolvesAccount;

    /** Above this a download is refused; the agent caps its own read too. */
    private const DOWNLOAD_LIMIT = 33554432;

    public function __construct(private readonly FileManager $files) {}

    // -----------------------------------------------------------------------
    // browse
    // -----------------------------------------------------------------------

    public function index(Request $request): View|RedirectResponse
    {
        $account = $this->requireAccount();

        try {
            $path = $this->files->normalise($account, (string) $request->query('path', $account->home()));
            $entries = $this->files->list($account, $path);
        } catch (Throwable $e) {
            return redirect()->route('panel.files.index')
                ->with('error', 'Could not open that folder: '.$e->getMessage());
        }

        // One agent call for the whole listing's folder sizes. The listing is
        // unsorted here on purpose: the view sorts, so the customer can too.
        $dirs = array_values(array_filter(
            array_map(fn ($e) => (string) ($e['path'] ?? ''), $entries),
            fn ($p) => $p !== ''
        ));

        $sizes = [];
        try {
            $sizes = $this->files->sizes($account, $dirs);
        } catch (Throwable) {
            // A size is a nicety; the listing is not allowed to fail for it.
        }

        $trash = [];
        try {
            $trash = $this->files->trashEntries($account);
        } catch (Throwable) {
            // No trash yet is the normal case for a new account.
        }

        return view('panel.files.index', [
            'account' => $account,
            'path' => $path,
            'entries' => $entries,
            'sizes' => $sizes,
            'crumbs' => $this->files->crumbs($path, $account->home()),
            'parent' => dirname($path),
            'trash' => $trash,
            'places' => $this->places($account),
            'sort' => (string) $request->query('sort', 'name'),
            'dir' => (string) $request->query('dir', 'asc') === 'desc' ? 'desc' : 'asc',
        ]);
    }

    /** The five folders a customer actually uses, shown as a left rail. */
    private function places($account): array
    {
        return array_values(array_filter([
            ['label' => 'public_html', 'path' => $account->home().'/public_html', 'icon' => 'globe'],
            ['label' => 'assets', 'path' => $account->home().'/assets', 'icon' => 'folder'],
            ['label' => 'logs', 'path' => $account->home().'/logs', 'icon' => 'list'],
            ['label' => 'etc', 'path' => $account->home().'/etc', 'icon' => 'settings'],
            ['label' => 'ssl', 'path' => $account->home().'/ssl', 'icon' => 'lock'],
        ], fn ($p) => str_starts_with($p['path'], $account->home())));
    }

    // -----------------------------------------------------------------------
    // read: editor, viewer, download
    // -----------------------------------------------------------------------

    public function edit(Request $request): View|RedirectResponse
    {
        $account = $this->requireAccount();
        $path = $this->files->normalise($account, (string) $request->query('path', ''));

        try {
            $file = $this->files->read($account, $path);
            $isNew = false;
        } catch (Throwable $e) {
            // A missing file opens the same editor, and saving creates it. Any
            // other failure is reported rather than silently swallowed.
            if (! str_contains(strtolower($e->getMessage()), 'not found')) {
                return redirect()->route('panel.files.index', ['path' => dirname($path)])
                    ->with('error', $e->getMessage());
            }

            $file = ['content' => '', 'truncated' => false, 'bytes' => 0];
            $isNew = true;
        }

        return view('panel.files.edit', [
            'account' => $account,
            'path' => $path,
            'file' => $file,
            'isNew' => $isNew,
            'looksBinary' => ! $isNew && $this->looksBinary((string) $file['content']),
            'crumbs' => $this->files->crumbs($path, $account->home()),
        ]);
    }

    /**
     * A read-only view of one file.
     *
     * Images come back as a data URI so a preview needs no second request and
     * no public URL. Text comes back as text. Everything else returns
     * `text => null`, and the view says "no preview" rather than printing
     * control characters into the page.
     */
    public function view(Request $request): View|RedirectResponse
    {
        $account = $this->requireAccount();
        $path = $this->files->normalise($account, (string) $request->query('path', ''));

        $name = basename($path);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $data = [
            'account' => $account,
            'path' => $path,
            'name' => $name,
            'ext' => $ext,
            'crumbs' => $this->files->crumbs($path, $account->home()),
            'kind' => 'binary',
            'text' => null,
            'dataUri' => null,
            'truncated' => false,
            'bytes' => 0,
            'reason' => null,
        ];

        try {
            $file = $this->files->download($account, $path);
        } catch (Throwable $e) {
            return redirect()->route('panel.files.index', ['path' => dirname($path)])
                ->with('error', 'Could not open that file: '.$e->getMessage());
        }

        $data['bytes'] = $file['size'];
        $data['truncated'] = $file['truncated'];

        $bytes = $file['bytes'];
        $mime = $file['mime'];

        // An image or a PDF is shown as it is, not as text.
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'bmp', 'avif'], true)) {
            $data['kind'] = $ext === 'svg' ? 'svg' : 'image';
            $data['dataUri'] = 'data:'.$this->imageMime($ext).';base64,'.base64_encode($bytes);

            return view('panel.files.view', $data);
        }

        if ($ext === 'pdf') {
            $data['kind'] = 'pdf';
            $data['dataUri'] = 'data:application/pdf;base64,'.base64_encode($bytes);

            return view('panel.files.view', $data);
        }

        // Everything else is judged by its bytes, not by its extension. A .txt
        // full of PNG data is binary, and a file with no extension can still be
        // a shell script somebody needs to read.
        if ($this->looksBinary($bytes)) {
            $data['kind'] = 'binary';
            $data['reason'] = $this->binaryReason($bytes);

            return view('panel.files.view', $data);
        }

        $data['kind'] = 'text';
        $data['text'] = $this->toUtf8($bytes);

        return view('panel.files.view', $data);
    }

    public function download(Request $request): StreamedResponse|RedirectResponse
    {
        $account = $this->requireAccount();
        $path = $this->files->normalise($account, (string) $request->query('path', ''));

        try {
            $file = $this->files->download($account, $path);
        } catch (Throwable $e) {
            return redirect()->route('panel.files.index', ['path' => dirname($path)])
                ->with('error', 'That file could not be downloaded: '.$e->getMessage());
        }

        if ($file['size'] > self::DOWNLOAD_LIMIT) {
            return redirect()->route('panel.files.index', ['path' => dirname($path)])
                ->with('error', sprintf(
                    'That file is %s, larger than the 32 MB download limit. Use FTP or the terminal for files this size.',
                    $this->human($file['size'])
                ));
        }

        $bytes = $file['bytes'];
        $name = basename($path);

        return response()->streamDownload(function () use ($bytes) {
            echo $bytes;
        }, $name, [
            'Content-Type' => $file['mime'] ?: 'application/octet-stream',
            'Content-Length' => (string) strlen($bytes),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    // -----------------------------------------------------------------------
    // search
    // -----------------------------------------------------------------------

    public function search(Request $request): View
    {
        $account = $this->requireAccount();

        $query = trim((string) $request->query('q', ''));
        $in = (string) $request->query('in', 'home');
        $path = $this->files->normalise($account, (string) $request->query('path', $account->home()));

        $scope = match ($in) {
            'public_html' => $account->home().'/public_html',
            'current' => $path,
            default => $account->home(),
        };

        $hits = [];

        // Globs are refused with an explanation rather than silently expanded:
        // a customer who types *.php should be told what to type instead.
        $invalid = null;
        if ($query !== '') {
            if (strpbrk($query, '*?[]') !== false) {
                $invalid = 'Search matches plain names. Enter part of a name — "config", not "config*".';
            } elseif (mb_strlen($query) > 200) {
                $invalid = 'That search is too long.';
            } else {
                try {
                    $hits = $this->files->search($account, $query, $scope);
                } catch (Throwable $e) {
                    $invalid = $e->getMessage();
                }
            }
        }

        return view('panel.files.search', [
            'account' => $account,
            'query' => $query,
            'in' => $in,
            'path' => $path,
            'scope' => $scope,
            'hits' => $hits,
            'invalid' => $invalid,
            'crumbs' => $this->files->crumbs($scope, $account->home()),
        ]);
    }

    // -----------------------------------------------------------------------
    // write
    // -----------------------------------------------------------------------

    public function update(UpdateFileRequest $request): RedirectResponse
    {
        $account = $this->requireAccount();

        try {
            $this->files->write(
                $account,
                (string) $request->input('path'),
                (string) $request->input('content', ''),
                (bool) $request->input('append', false),
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'The file was not saved: '.$e->getMessage());
        }

        return redirect()->route('panel.files.index', ['path' => dirname((string) $request->input('path'))])
            ->with('status', 'File saved.');
    }

    /**
     * Upload, one file or many.
     *
     * Accepts `files[]` from the picker and `file` from the drag-and-drop
     * fallback, and `dir` as the destination folder so a multi-file upload
     * lands where the customer is standing rather than at their home.
     */
    public function upload(Request $request): RedirectResponse
    {
        $account = $this->requireAccount();

        $data = $request->validate([
            'dir' => ['required', 'string', 'max:4096', 'regex:#^/home/[a-z][a-z0-9]{2,15}(/|$)#', 'not_regex:/\\.\\./'],
            'overwrite' => ['nullable', 'boolean'],
            'file' => ['nullable', 'file', 'max:32768'],
            'files' => ['nullable', 'array', 'max:20'],
            'files.*' => ['file', 'max:32768'],
        ]);

        $dir = (string) $data['dir'];
        $overwrite = (bool) ($data['overwrite'] ?? true);

        $incoming = [];

        if ($request->hasFile('files')) {
            foreach ((array) $request->file('files') as $file) {
                if ($file) {
                    $incoming[] = $file;
                }
            }
        } elseif ($request->hasFile('file')) {
            $incoming[] = $request->file('file');
        }

        if ($incoming === []) {
            return back()->with('error', 'Choose at least one file to upload.');
        }

        $done = 0;
        $skipped = [];

        foreach ($incoming as $file) {
            $name = $file->getClientOriginalName();

            try {
                $this->files->assertName($name);
                $this->files->upload(
                    $account,
                    $dir,
                    $name,
                    (string) file_get_contents($file->getRealPath()),
                    $overwrite,
                );
                $done++;
            } catch (Throwable $e) {
                // One refused file does not fail the batch: a customer dropping
                // twelve files should not have to work out which one broke it.
                $skipped[] = $name.': '.$e->getMessage();
            }
        }

        if ($done === 0) {
            return back()->with('error', 'Nothing was uploaded. '.implode(' ', $skipped));
        }

        $message = $done === 1 ? '1 file uploaded.' : $done.' files uploaded.';

        if ($skipped !== []) {
            $message .= ' Skipped — '.implode(' ', $skipped);
        }

        return back()->with('status', $message);
    }

    public function mkdir(Request $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $data = $request->validate([
            'path' => ['required', 'string', 'max:4096', 'regex:#^/home/[a-z][a-z0-9]{2,15}/#', 'not_regex:/\\.\\./'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $target = (string) $data['path'];

        // Two shapes arrive here: a finished path from the dialog, or a parent
        // plus a name. Both end up as one path.
        if (! empty($data['name'])) {
            try {
                $this->files->assertName((string) $data['name']);
            } catch (Throwable $e) {
                return back()->with('error', 'The folder was not created: '.$e->getMessage());
            }

            $target = rtrim($target, '/').'/'.$data['name'];
        }

        try {
            $this->files->mkdir($account, $target);
        } catch (Throwable $e) {
            return back()->with('error', 'The folder was not created: '.$e->getMessage());
        }

        return back()->with('status', 'Folder created.');
    }

    public function rename(Request $request): RedirectResponse
    {
        $account = $this->requireAccount();
        $data = $request->validate([
            'from' => ['required', 'string', 'max:4096', 'regex:#^/home/[a-z][a-z0-9]{2,15}/#', 'not_regex:/\\.\\./'],
            'to' => ['nullable', 'string', 'max:4096', 'regex:#^/home/[a-z][a-z0-9]{2,15}/#', 'not_regex:/\\.\\./'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $from = (string) $data['from'];

        $to = (string) ($data['to'] ?? '');
        if ($to === '' && ! empty($data['name'])) {
            try {
                $this->files->assertName((string) $data['name']);
            } catch (Throwable $e) {
                return back()->with('error', 'The item was not renamed: '.$e->getMessage());
            }

            $to = dirname($from).'/'.$data['name'];
        }

        if ($to === '') {
            return back()->with('error', 'The item was not renamed: a new name is required.');
        }

        try {
            $this->files->move($account, $from, $to, false);
        } catch (Throwable $e) {
            return back()->with('error', 'The item was not renamed: '.$e->getMessage());
        }

        return back()->with('status', 'Renamed.');
    }

    public function copy(Request $request): RedirectResponse
    {
        $account = $this->requireAccount();

        [$sources, $to, $overwrite] = $this->selection($request);

        if ($sources === []) {
            return back()->with('error', 'Select something to copy.');
        }

        if ($to === '') {
            return back()->with('error', 'Enter where the copy should go.');
        }

        $done = 0;
        $failed = [];

        foreach ($this->targets($sources, $to) as [$from, $target]) {
            try {
                $this->files->copy($account, $from, $target, $overwrite);
                $done++;
            } catch (Throwable $e) {
                $failed[] = basename($from).': '.$e->getMessage();
            }
        }

        if ($done === 0) {
            return back()->with('error', 'Nothing was copied. '.implode(' ', $failed));
        }

        return back()->with(
            'status',
            ($done === 1 ? '1 item copied.' : $done.' items copied.')
            .($failed !== [] ? ' Skipped — '.implode(' ', $failed) : '')
        );
    }

    public function move(Request $request): RedirectResponse
    {
        $account = $this->requireAccount();

        [$sources, $to, $overwrite] = $this->selection($request);

        if ($sources === []) {
            return back()->with('error', 'Select something to move.');
        }

        if ($to === '') {
            return back()->with('error', 'Enter where the items should go.');
        }

        $done = 0;
        $failed = [];

        foreach ($this->targets($sources, $to) as [$from, $target]) {
            try {
                $this->files->move($account, $from, $target, $overwrite);
                $done++;
            } catch (Throwable $e) {
                $failed[] = basename($from).': '.$e->getMessage();
            }
        }

        if ($done === 0) {
            return back()->with('error', 'Nothing was moved. '.implode(' ', $failed));
        }

        return back()->with(
            'status',
            ($done === 1 ? '1 item moved.' : $done.' items moved.')
            .($failed !== [] ? ' Skipped — '.implode(' ', $failed) : '')
        );
    }

    public function chmod(Request $request): RedirectResponse
    {
        $account = $this->requireAccount();

        [$sources] = $this->selection($request);

        $data = $request->validate([
            'mode' => ['required', 'string', 'regex:/^0?[0-7]{3}$/'],
            'recursive' => ['nullable', 'boolean'],
        ]);

        if ($sources === []) {
            return back()->with('error', 'Select something to change permissions on.');
        }

        $mode = str_pad(ltrim((string) $data['mode'], '0'), 4, '0', STR_PAD_LEFT);
        $recursive = (bool) ($data['recursive'] ?? false);

        $done = 0;
        $failed = [];

        foreach ($sources as $path) {
            try {
                $this->files->chmod($account, $path, $mode, $recursive);
                $done++;
            } catch (Throwable $e) {
                $failed[] = basename($path).': '.$e->getMessage();
            }
        }

        if ($done === 0) {
            return back()->with('error', 'No permissions were changed. '.implode(' ', $failed));
        }

        return back()->with(
            'status',
            'Permissions set to '.$mode.' on '.($done === 1 ? '1 item.' : $done.' items.')
            .($failed !== [] ? ' Skipped — '.implode(' ', $failed) : '')
        );
    }

    public function compress(Request $request): RedirectResponse
    {
        $account = $this->requireAccount();

        [$sources] = $this->selection($request);

        $data = $request->validate([
            'archive' => ['nullable', 'string', 'max:4096', 'regex:#^/home/[a-z][a-z0-9]{2,15}/#', 'not_regex:/\\.\\./'],
            'name' => ['nullable', 'string', 'max:255'],
            'dir' => ['nullable', 'string', 'max:4096', 'regex:#^/home/[a-z][a-z0-9]{2,15}(/|$)#', 'not_regex:/\\.\\./'],
            'format' => ['required', 'in:zip,tar.gz'],
        ]);

        if ($sources === []) {
            return back()->with('error', 'Select something to compress.');
        }

        $format = (string) $data['format'];
        $suffix = $format === 'zip' ? '.zip' : '.tar.gz';

        $archive = (string) ($data['archive'] ?? '');

        if ($archive === '') {
            $name = trim((string) ($data['name'] ?? ''));

            if ($name === '') {
                // Name it after the first item, which is what people expect.
                $name = basename(rtrim($sources[0], '/'));
            }

            if (! str_ends_with($name, $suffix)) {
                $name .= $suffix;
            }

            try {
                $this->files->assertName($name);
            } catch (Throwable $e) {
                return back()->with('error', 'The archive was not created: '.$e->getMessage());
            }

            $dir = (string) ($data['dir'] ?? dirname($sources[0]));
            $archive = rtrim($dir, '/').'/'.$name;
        }

        try {
            $this->files->compress($account, $sources, $archive, $format);
        } catch (Throwable $e) {
            return back()->with('error', 'The archive was not created: '.$e->getMessage());
        }

        return back()->with('status', 'Created '.basename($archive).'.');
    }

    public function extract(Request $request): RedirectResponse
    {
        $account = $this->requireAccount();

        $data = $request->validate([
            'archive' => ['required', 'string', 'max:4096', 'regex:#^/home/[a-z][a-z0-9]{2,15}/#', 'not_regex:/\\.\\./'],
            'dest' => ['nullable', 'string', 'max:4096', 'regex:#^/home/[a-z][a-z0-9]{2,15}(/|$)#', 'not_regex:/\\.\\./'],
            'dir' => ['nullable', 'string', 'max:4096', 'regex:#^/home/[a-z][a-z0-9]{2,15}(/|$)#', 'not_regex:/\\.\\./'],
        ]);

        $archive = (string) $data['archive'];
        $dest = (string) ($data['dest'] ?? $data['dir'] ?? dirname($archive));

        try {
            $result = $this->files->extract($account, $archive, $dest);
        } catch (Throwable $e) {
            return back()->with('error', 'That archive was not extracted: '.$e->getMessage());
        }

        $files = (int) ($result['files'] ?? 0);

        return back()->with('status', 'Extracted '.($files === 1 ? '1 file' : $files.' files').'.');
    }

    // -----------------------------------------------------------------------
    // delete and the trash
    // -----------------------------------------------------------------------

    public function destroy(Request $request): RedirectResponse
    {
        $account = $this->requireAccount();

        [$sources] = $this->selection($request, allowHome: true);

        if ($sources === []) {
            return back()->with('error', 'Select something to delete.');
        }

        $done = 0;
        $failed = [];

        foreach ($sources as $path) {
            try {
                $this->files->trash($account, $path);
                $done++;
            } catch (Throwable $e) {
                $failed[] = basename($path).': '.$e->getMessage();
            }
        }

        if ($done === 0) {
            return back()->with('error', 'Nothing was deleted. '.implode(' ', $failed));
        }

        return back()->with(
            'status',
            $done === 1
                ? 'Moved to the trash. Restore it from the Trash panel.'
                : $done.' items moved to the trash.'
        );
    }

    public function restore(Request $request): RedirectResponse
    {
        $account = $this->requireAccount();

        $data = $request->validate([
            'path' => ['required', 'string', 'max:4096', 'not_regex:/\\.\\./'],
        ]);

        try {
            $result = $this->files->restore($account, (string) $data['path']);
        } catch (Throwable $e) {
            return back()->with('error', 'That item was not restored: '.$e->getMessage());
        }

        return back()->with('status', 'Restored to '.($result['restored'] ?? 'its original place').'.');
    }

    public function emptyTrash(Request $request): RedirectResponse
    {
        $account = $this->requireAccount();

        try {
            $removed = $this->files->emptyTrash($account);
        } catch (Throwable $e) {
            return back()->with('error', 'The trash was not emptied: '.$e->getMessage());
        }

        if ($removed === 0) {
            return back()->with('status', 'The trash is already empty.');
        }

        return back()->with('status', 'Permanently deleted '.($removed === 1 ? '1 item' : $removed.' items').'.');
    }

    // -----------------------------------------------------------------------
    // shared helpers
    // -----------------------------------------------------------------------

    /**
     * Reads the selection in either shape the UI sends it: `paths[]` from the
     * toolbar or a single `from`. Validation is deliberately loose on length
     * here and strict in the service, so every refusal has one home.
     *
     * @return array{0: array<int, string>, 1: string, 2: bool}
     */
    private function selection(Request $request, bool $allowHome = false): array
    {
        $data = $request->validate([
            'paths' => ['nullable', 'array', 'max:200'],
            'paths.*' => ['string', 'max:4096', 'not_regex:/\\.\\./'],
            'path' => ['nullable', 'string', 'max:4096', 'not_regex:/\\.\\./'],
            'from' => ['nullable', 'string', 'max:4096', 'not_regex:/\\.\\./'],
            'to' => ['nullable', 'string', 'max:4096', 'not_regex:/\\.\\./'],
            'overwrite' => ['nullable', 'boolean'],
        ]);

        // Three shapes arrive from the same toolbar: `paths[]` from a multi
        // selection, `path` from the single-item menu, `from` from the dialogs.
        $paths = array_filter(array_map('strval', (array) ($data['paths'] ?? [])));

        if ($paths === [] && ! empty($data['path'])) {
            $paths = [(string) $data['path']];
        }

        if ($paths === [] && ! empty($data['from'])) {
            $paths = [(string) $data['from']];
        }

        $account = $this->requireAccount();
        $home = $account->home();

        if (! $allowHome) {
            $paths = array_values(array_filter($paths, fn ($p) => rtrim($p, '/') !== $home));
        }

        return [$paths, (string) ($data['to'] ?? ''), (bool) ($data['overwrite'] ?? false)];
    }

    /**
     * Pair each source with its destination.
     *
     * A trailing slash means "into this folder", and so does more than one
     * source: two things cannot both be given one file name. Anything else is
     * a full path, which is what the rename dialog sends.
     *
     * @param  array<int, string>  $sources
     * @return array<int, array{0: string, 1: string}>
     */
    private function targets(array $sources, string $to): array
    {
        $intoFolder = str_ends_with($to, '/') || count($sources) > 1;
        $folder = rtrim($to, '/');

        $out = [];

        foreach ($sources as $from) {
            $out[] = [$from, $intoFolder ? $folder.'/'.basename(rtrim($from, '/')) : $folder];
        }

        return $out;
    }

    /**
     * Is this bytes-a-file or text? Decided by content, never by extension: a
     * control byte or invalid UTF-8 means no text preview, and a NUL in the
     * first few kilobytes is enough to be sure.
     */
    private function looksBinary(string $bytes): bool
    {
        if ($bytes === '') {
            return false;
        }

        $head = substr($bytes, 0, 8000);

        if (preg_match('/[\x00-\x08\x0B\x0E-\x1F]/', $head) === 1) {
            return true;
        }

        return ! mb_check_encoding($head, 'UTF-8');
    }

    private function binaryReason(string $bytes): string
    {
        $head = substr($bytes, 0, 16);

        if (str_starts_with($head, "\x89PNG")) {
            return 'This is a PNG image.';
        }
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'This is a JPEG image.';
        }
        if (str_starts_with($head, 'GIF8')) {
            return 'This is a GIF image.';
        }
        if (str_starts_with($head, '%PDF')) {
            return 'This is a PDF document.';
        }
        if (str_starts_with($head, 'PK')) {
            return 'This is a ZIP archive.';
        }
        if (str_starts_with($head, "\x1F\x8B")) {
            return 'This is a gzip archive.';
        }
        if (str_starts_with($head, "\x7FELF")) {
            return 'This is a compiled executable.';
        }

        return 'This file does not contain text, so there is nothing safe to show.';
    }

    private function toUtf8(string $bytes): string
    {
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }

        return (string) mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
    }

    private function imageMime(string $ext): string
    {
        return match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'bmp' => 'image/bmp',
            'avif' => 'image/avif',
            default => 'application/octet-stream',
        };
    }

    private function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $i => $unit) {
            if ($bytes < 1024 ** ($i + 1) || $unit === 'GB') {
                return round($bytes / (1024 ** $i), $i === 0 ? 0 : 1).' '.$unit;
            }
        }

        return $bytes.' B';
    }
}
