<?php

namespace App\Domain\Hosting;

use App\Domain\Agent\Audit;
use App\Models\Account;
use RuntimeException;

/**
 * A thin, careful wrapper over the agent's jailed file operations.
 *
 * Path handling happens in two places on purpose: here (so the UI gets a clear
 * error before a request is signed) and inside the agent (so a compromised
 * panel still cannot leave /home/<user>).
 */
class FileManager
{
    public function __construct(private readonly Audit $audit) {}

    /** @return array<int, array<string, mixed>> */
    public function list(Account $account, string $path): array
    {
        $result = $this->audit->dispatch('file.list', [
            'username' => $account->username,
            'path' => $this->normalise($account, $path),
        ], $account);

        return $result['entries'] ?? [];
    }

    /** @return array{content: string, truncated: bool, bytes: int} */
    public function read(Account $account, string $path): array
    {
        $result = $this->audit->dispatch('file.read', [
            'username' => $account->username,
            'path' => $this->normalise($account, $path),
            'max_bytes' => 1048576,
        ], $account);

        return [
            'content' => (string) ($result['content'] ?? ''),
            'truncated' => (bool) ($result['truncated'] ?? false),
            'bytes' => (int) ($result['bytes'] ?? 0),
        ];
    }

    public function write(Account $account, string $path, string $content, bool $append = false): void
    {
        $this->audit->dispatch('file.write', [
            'username' => $account->username,
            'path' => $this->normalise($account, $path),
            'content' => $content,
            'append' => $append,
        ], $account);
    }

    public function mkdir(Account $account, string $path): void
    {
        $this->audit->dispatch('file.mkdir', [
            'username' => $account->username,
            'path' => $this->normalise($account, $path),
        ], $account);
    }

    public function delete(Account $account, string $path): void
    {
        $clean = $this->normalise($account, $path);

        if ($clean === $account->home()) {
            throw new RuntimeException('The account home cannot be deleted here.');
        }

        $this->audit->dispatch('file.delete', [
            'username' => $account->username,
            'path' => $clean,
        ], $account);
    }

    public function rename(Account $account, string $from, string $to): void
    {
        $this->audit->dispatch('file.rename', [
            'username' => $account->username,
            'from' => $this->normalise($account, $from),
            'to' => $this->normalise($account, $to),
        ], $account);
    }

    /**
     * Resolves "." and ".." segments and guarantees the result is inside the
     * account's home. Anything else is a bug in the caller, not user input.
     */
    public function normalise(Account $account, string $path): string
    {
        $home = $account->home();
        $path = $path === '' ? $home : $path;

        // A NUL or any other control byte is never part of a real path, and a
        // NUL in particular truncates a path inside libc. Refused here rather
        // than left for PHP's filesystem functions to trip over.
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new RuntimeException('That path contains a control character.');
        }

        if (! str_starts_with($path, '/')) {
            $path = rtrim($home, '/').'/'.ltrim($path, '/');
        }

        $parts = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($parts === []) {
                    throw new RuntimeException('That path leaves the account.');
                }
                array_pop($parts);

                continue;
            }

            $parts[] = $segment;
        }

        $clean = '/'.implode('/', $parts);

        if ($clean !== $home && ! str_starts_with($clean, $home.'/')) {
            throw new RuntimeException('That path is outside the account.');
        }

        return $clean;
    }

    /** The parent path and basename, for breadcrumbs. */
    public function crumbs(string $path, string $home): array
    {
        $relative = trim(substr($path, strlen($home)), '/');
        $crumbs = [['label' => 'Home', 'path' => $home]];
        $walk = $home;

        foreach ($relative === '' ? [] : explode('/', $relative) as $segment) {
            $walk .= '/'.$segment;
            $crumbs[] = ['label' => $segment, 'path' => $walk];
        }

        return $crumbs;
    }

    // -----------------------------------------------------------------------
    // The cPanel-class set: the operations below this line are what makes the
    // manager a file manager rather than a text editor with a listing.
    // -----------------------------------------------------------------------

    /** Refuse a name that could not survive on disk, before a request is signed. */
    public function assertName(string $name): void
    {
        if ($name === '' || $name === '.' || $name === '..') {
            throw new RuntimeException('That name is not usable.');
        }

        if (str_contains($name, '/') || str_contains($name, "\0")) {
            throw new RuntimeException('A name cannot contain a slash.');
        }

        if (strlen($name) > 255) {
            throw new RuntimeException('That name is longer than 255 bytes.');
        }
    }

    /**
     * Upload a file into a folder. The agent receives the bytes as base64
     * because the envelope is JSON.
     */
    public function upload(Account $account, string $dir, string $name, string $bytes, bool $overwrite = true): array
    {
        $this->assertName($name);

        $path = $this->normalise($account, rtrim($dir, '/').'/'.$name);

        return $this->audit->dispatch('file.upload', [
            'username' => $account->username,
            'path' => $path,
            'content' => base64_encode($bytes),
            'overwrite' => $overwrite,
        ], $account);
    }

    /** The bytes and a content type for a download, so the panel can stream it. */
    public function download(Account $account, string $path): array
    {
        $result = $this->audit->dispatch('file.download', [
            'username' => $account->username,
            'path' => $this->normalise($account, $path),
            'max_bytes' => 33554432,
        ], $account);

        $encoded = (string) ($result['content'] ?? '');
        $bytes = base64_decode($encoded, true);

        if ($bytes === false) {
            throw new RuntimeException('The agent returned a payload the panel could not decode.');
        }

        return [
            'bytes' => $bytes,
            'size' => (int) ($result['bytes'] ?? strlen($bytes)),
            'truncated' => (bool) ($result['truncated'] ?? false),
            'mime' => (string) ($result['mime_type'] ?? 'application/octet-stream'),
        ];
    }

    /**
     * Copy one path to another. When the target is a folder the source keeps its
     * own name inside it, which is what every file manager does and what people
     * expect from "copy to this folder".
     */
    public function copy(Account $account, string $from, string $to, bool $overwrite = false): array
    {
        $cleanFrom = $this->normalise($account, $from);
        $cleanTo = $this->normalise($account, $to);

        if ($cleanFrom === $cleanTo) {
            throw new RuntimeException('The source and the destination are the same path.');
        }

        if (str_starts_with($cleanTo.'/', $cleanFrom.'/')) {
            throw new RuntimeException('A folder cannot be copied inside itself.');
        }

        if ($cleanFrom === $account->home()) {
            throw new RuntimeException('The account home cannot be copied.');
        }

        return $this->audit->dispatch('file.copy', [
            'username' => $account->username,
            'from' => $cleanFrom,
            'to' => $cleanTo,
            'overwrite' => $overwrite,
        ], $account);
    }

    /**
     * Move, as a real rename.
     *
     * Copy-then-delete was the obvious implementation and the wrong one: it
     * leaves a window where the customer has two copies, and a failure between
     * the two steps needs a human to sort out. os.Rename inside one home is on
     * one filesystem, so it is atomic and either happens or does not.
     */
    public function move(Account $account, string $from, string $to, bool $overwrite = false): array
    {
        $cleanFrom = $this->normalise($account, $from);
        $cleanTo = $this->normalise($account, $to);

        if ($cleanFrom === $cleanTo) {
            throw new RuntimeException('The source and the destination are the same path.');
        }

        if (str_starts_with($cleanTo.'/', $cleanFrom.'/')) {
            throw new RuntimeException('A folder cannot be moved inside itself.');
        }

        if ($cleanFrom === $account->home()) {
            throw new RuntimeException('The account home cannot be moved.');
        }

        if ($overwrite) {
            // os.Rename replaces the destination silently, so an overwrite has
            // to be an explicit delete first — one the customer asked for.
            $this->trash($account, $cleanTo);
        }

        return $this->audit->dispatch('file.rename', [
            'username' => $account->username,
            'from' => $cleanFrom,
            'to' => $cleanTo,
        ], $account);
    }

    /**
     * The agent's rule, mirrored. A mode that removes owner read, or that is
     * group- and world-writable at once, is how a site gets owned — so it is
     * refused with the same wording the agent would use.
     */
    public function assertMode(string $mode): void
    {
        if (! preg_match('/^[0-7]{3,4}$/', $mode)) {
            throw new RuntimeException('A mode is three or four octal digits, e.g. 644 or 0755.');
        }

        $value = octdec($mode);

        if (($value & 0o400) === 0) {
            throw new RuntimeException('That mode removes owner read — refusing.');
        }

        if (($value & 0o002) !== 0 && ($value & 0o020) !== 0) {
            throw new RuntimeException('That mode is world-writable and group-writable at once — use 775 or 755.');
        }
    }

    public function chmod(Account $account, string $path, string $mode, bool $recursive = false): array
    {
        $clean = $this->normalise($account, $path);

        $this->assertMode($mode);

        if ($recursive && $clean === $account->home()) {
            throw new RuntimeException('Changing every file in the account at once is refused; pick a folder.');
        }

        return $this->audit->dispatch('file.chmod', [
            'username' => $account->username,
            'path' => $clean,
            'mode' => $mode,
            'recursive' => $recursive,
        ], $account);
    }

    public function compress(Account $account, array $paths, string $archive, string $format): array
    {
        $clean = array_map(fn ($p) => $this->normalise($account, (string) $p), $paths);

        if ($clean === []) {
            throw new RuntimeException('Choose at least one item to compress.');
        }

        if (! in_array($format, ['zip', 'tar.gz'], true)) {
            throw new RuntimeException('Choose zip or tar.gz.');
        }

        $target = $this->normalise($account, $archive);

        if ($format === 'zip' && ! str_ends_with($target, '.zip')) {
            throw new RuntimeException('A zip archive has to be named .zip.');
        }

        if ($format === 'tar.gz' && ! str_ends_with($target, '.tar.gz')) {
            throw new RuntimeException('A tar.gz archive has to be named .tar.gz.');
        }

        return $this->audit->dispatch('file.compress', [
            'username' => $account->username,
            'paths' => array_values($clean),
            'archive' => $target,
            'format' => $format,
        ], $account);
    }

    public function extract(Account $account, string $archive, string $dest): array
    {
        return $this->audit->dispatch('file.extract', [
            'username' => $account->username,
            'archive' => $this->normalise($account, $archive),
            'dest' => $this->normalise($account, $dest),
        ], $account);
    }

    /** @return array<int, array<string, mixed>> */
    public function search(Account $account, string $query, string $scope): array
    {
        $result = $this->audit->dispatch('file.search', [
            'username' => $account->username,
            'path' => $this->normalise($account, $scope),
            'query' => $query,
            'limit' => 200,
        ], $account);

        return $result['hits'] ?? [];
    }

    /**
     * Sizes for a set of paths in one call. A listing of 500 files would be 500
     * round trips otherwise, and folder sizes are what makes a listing useful.
     *
     * @param  array<int, string>  $paths
     * @return array<string, int>
     */
    public function sizes(Account $account, array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $result = $this->audit->dispatch('file.stat', [
            'username' => $account->username,
            'paths' => array_values(array_map(fn ($p) => $this->normalise($account, (string) $p), $paths)),
        ], $account);

        $out = [];
        foreach ($result['sizes'] ?? [] as $row) {
            $out[(string) ($row['path'] ?? '')] = (int) ($row['bytes'] ?? 0);
        }

        return $out;
    }

    /** Delete, but only as far as the trash: nothing is unlinked by this button. */
    public function trash(Account $account, string $path): array
    {
        $clean = $this->normalise($account, $path);

        if ($clean === $account->home()) {
            throw new RuntimeException('The account home cannot be deleted.');
        }

        return $this->audit->dispatch('file.trash', [
            'username' => $account->username,
            'path' => $clean,
        ], $account);
    }

    public function restore(Account $account, string $entry): array
    {
        $trash = $account->home().'/.atlas-trash/';

        // The agent refuses this too. Both layers check because a restore with
        // a general path would be a "move any file anywhere" primitive, and one
        // layer is never enough for that.
        if (! str_starts_with($entry, $trash) || substr_count(trim(substr($entry, strlen($trash))), '/') > 0) {
            throw new RuntimeException('That is not a trash entry. Restore works on items in the trash.');
        }

        return $this->audit->dispatch('file.restore', [
            'username' => $account->username,
            'path' => $entry,
        ], $account);
    }

    /** @return array<int, array<string, mixed>> */
    public function trashEntries(Account $account): array
    {
        $result = $this->audit->dispatch('file.trash.list', [
            'username' => $account->username,
        ], $account);

        return $result['entries'] ?? [];
    }

    /**
     * Empty the trash. This is the one irreversible action in the manager, so it
     * is a separate method with its own name rather than a flag on delete.
     *
     * Requests go through the agent's delete, which is the only operation that
     * unlinks; the panel never removes a customer file itself.
     */
    public function emptyTrash(Account $account): int
    {
        $removed = 0;

        foreach ($this->trashEntries($account) as $entry) {
            $path = (string) ($entry['path'] ?? '');

            if ($path === '' || ! str_contains($path, '/.atlas-trash/')) {
                continue;
            }

            $this->audit->dispatch('file.delete', [
                'username' => $account->username,
                'path' => $path,
            ], $account);

            $removed++;
        }

        return $removed;
    }
}
