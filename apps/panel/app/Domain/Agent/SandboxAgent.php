<?php

namespace App\Domain\Agent;

use Illuminate\Support\Str;

/**
 * A development-only stand-in for the real agent.
 *
 * It exists so the panel can be evaluated on a laptop or in a container with
 * no root, no Apache and no MariaDB. It is bound only when
 * ATLAS_AGENT_DRIVER=sandbox, every screen shows a banner saying so, and it
 * never claims to have touched the machine — the filesystem it edits lives in
 * storage/app/sandbox.
 */
class SandboxAgent implements Agent
{
    public function __construct(private readonly string $root = '') {}

    public function call(string $op, array $args = []): array
    {
        return match ($op) {
            'system.stats' => $this->stats(),
            'account.create' => $this->createAccount($args),
            'account.suspend' => ['username' => $args['username'] ?? '', 'status' => 'suspended'],
            'account.unsuspend' => ['username' => $args['username'] ?? '', 'status' => 'active'],
            'account.delete' => ['username' => $args['username'] ?? '', 'data_kept' => (bool) ($args['keep_data'] ?? true)],
            'account.quota.set' => ['username' => $args['username'] ?? ''],
            'vhost.write' => ['domain' => $args['domain'] ?? '', 'written' => true],
            'domain.remove' => $this->ok(['removed' => true]),
            'vhost.php.set' => $this->ok(['php_version' => $args['php_version'] ?? '']),
            'vhost.remove' => ['domain' => $args['domain'] ?? '', 'removed' => true],
            'db.create' => ['name' => $args['name'] ?? '', 'created' => true],
            'db.drop' => ['name' => $args['name'] ?? '', 'dropped' => true],
            'mail.mailbox.create' => ['address' => ($args['local_part'] ?? '').'@'.($args['domain'] ?? '')],
            'mail.mailbox.delete' => ['deleted' => true],
            'dns.zone.write' => ['domain' => $args['domain'] ?? '', 'records' => count($args['records'] ?? [])],
            'ssl.issue' => ['domain' => $args['domain'] ?? '', 'issuer' => 'Sandbox CA', 'expires_at' => now()->addDays(90)->toIso8601String()],
            'cron.set' => ['username' => $args['username'] ?? '', 'lines' => substr_count((string) ($args['entry'] ?? ''), "\n") + 1],
            'backup.run' => $this->runBackup($args),
            'backup.restore' => ['restored' => true],
            'service.restart' => ['unit' => $args['unit'] ?? '', 'state' => 'running'],
            'file.list' => $this->listFiles($args),
            'file.read' => $this->readFile($args),
            'file.write' => $this->writeFile($args),
            'file.mkdir' => $this->mkdir($args),
            'file.delete' => $this->deletePath($args),
            'file.rename' => $this->renamePath($args),

            // ---- hosting panel feature set -------------------------------
            // The sandbox answers every operation the panel can send, so a
            // screen is never untestable just because there is no root here.
            'domain.subdomain.create' => $this->ok(['domain' => ($args['label'] ?? '').'.'.($args['domain'] ?? '')]),
            'domain.alias.create' => $this->ok(['aliases' => $args['aliases'] ?? []]),
            'htaccess.redirect.write' => $this->writeRedirect($args),
            'htaccess.redirect.remove' => $this->ok(['removed' => true]),

            'ftp.account.create' => $this->ok(['ftp_user' => $args['ftp_username'] ?? '']),
            'ftp.account.drop' => $this->ok(['deleted' => true]),
            'ftp.account.password' => $this->ok(['updated' => true]),

            'mail.forwarder.write' => $this->ok(['destinations' => $args['destinations'] ?? []]),
            'mail.forwarder.remove' => $this->ok(['removed' => true]),
            'mail.autoresponder.write' => $this->ok(['active' => true]),
            'mail.autoresponder.remove' => $this->ok(['removed' => true]),
            'mail.catchall.write' => $this->ok(['destination' => $args['destination'] ?? '']),
            'mail.catchall.remove' => $this->ok(['removed' => true]),
            'mail.filter.write' => $this->ok(['rules' => count($args['rules'] ?? [])]),
            'mail.spam.write' => $this->ok(['threshold' => $args['threshold'] ?? '5.0']),
            'mail.dkim.ensure' => $this->dkim($args),
            'mail.list.write' => $this->ok(['members' => count($args['members'] ?? [])]),
            'mail.list.remove' => $this->ok(['removed' => true]),

            'ssl.csr' => $this->csr($args),
            'ssl.install' => $this->ok([
                'domain' => $args['domain'] ?? '',
                'issued' => true,
                'expires_at' => now()->addDays(365)->toIso8601String(),
                'message' => 'Sandbox CA accepted the certificate',
            ]),
            'ssl.info' => $this->ok(['domain' => $args['domain'] ?? '', 'present' => false]),

            'ip.block.write' => $this->ok(['cidr' => $args['cidr'] ?? '']),
            'ip.block.remove' => $this->ok(['removed' => true]),
            'dir.protect.write' => $this->ok(['protected' => true]),
            'dir.protect.remove' => $this->ok(['protected' => false]),

            'mime.write' => $this->ok(['extension' => $args['extension'] ?? '']),
            'mime.remove' => $this->ok(['removed' => true]),
            'errorpage.write' => $this->ok(['status' => $args['status'] ?? 0]),
            'php.settings.write' => $this->ok(['directives' => count($args['settings'] ?? [])]),
            'php.extensions' => $this->extensions($args),

            'app.install' => $this->installApp($args),
            'app.remove' => $this->ok(['removed' => true]),
            'app.info' => $this->ok([
                'slug' => 'wordpress', 'version' => '6.7', 'path' => $args['path'] ?? '',
                'up_to_date' => true, 'available' => '6.7',
            ]),

            'git.init' => $this->ok(['branch' => $args['branch'] ?? 'main']),
            'git.pull' => $this->ok(['output' => 'Already up to date.']),

            'db.user.create' => $this->ok(['user' => $args['username'] ?? '']),
            'db.user.drop' => $this->ok(['dropped' => true]),
            'db.user.password' => $this->ok(['updated' => true]),
            'db.remote.set' => $this->ok(['allowed' => (bool) ($args['allow'] ?? false)]),

            'bandwidth.report' => $this->bandwidth($args),
            'disk.usage' => $this->diskUsage($args),
            'log.auth' => $this->ok([]),

            // ---- the cPanel-class file manager and SSH -------------------
            // These operate on the sandbox filesystem, so upload, rename and
            // trash behave the way they will on a real server. The three that
            // would need root — chmod, compress and extract — are simulated
            // and say so in their result.
            'file.upload' => $this->uploadFile($args),
            'file.download' => $this->downloadFile($args),
            'file.copy' => $this->copyPath($args),
            'file.chmod' => $this->ok([
                'path' => $args['path'] ?? '', 'mode' => $args['mode'] ?? '', 'changed' => 1,
                'simulated' => true,
            ]),
            'file.compress' => $this->ok([
                'archive' => $args['archive'] ?? '', 'files' => count($args['paths'] ?? []),
                'simulated' => true,
            ]),
            'file.extract' => $this->ok(['files' => 0, 'simulated' => true]),
            'file.search' => $this->searchFiles($args),
            'file.stat' => $this->statPaths($args),
            'file.trash' => $this->trashPath($args),
            'file.restore' => $this->restorePath($args),
            'file.trash.list' => $this->listTrash($args),

            'ssh.key.generate' => $this->generateKey($args),
            'ssh.key.import' => $this->importKey($args),
            'ssh.key.list' => $this->listKeys($args),
            'ssh.key.authorize' => $this->authorizeKey($args, true),
            'ssh.key.deauthorize' => $this->authorizeKey($args, false),
            'ssh.key.delete' => $this->deleteKey($args),
            'ssh.access.set' => $this->ok([
                'username' => $args['username'] ?? '',
                'enabled' => (bool) ($args['enabled'] ?? false),
                'simulated' => true,
            ]),
            'ssh.info' => $this->ok([
                'enabled' => true,
                'port' => 22,
                'host_keys' => [
                    ['type' => 'ed25519', 'fingerprint' => 'SHA256:sandbox0Ed25519HostKeyNotReal0000000000000000'],
                    ['type' => 'rsa', 'fingerprint' => 'SHA256:sandbox0RsaHostKeyNotReal00000000000000000000'],
                ],
                'algorithms' => ['ed25519', 'rsa'],
                'simulated' => true,
            ]),

            default => throw new AgentException('Sandbox agent does not implement '.$op.'.', $op),
        };
    }

    public function healthy(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    private function stats(): array
    {
        $home = $this->root();

        return [
            'hostname' => gethostname() ?: 'sandbox',
            'load_avg' => array_map(fn ($v) => round($v, 2), sys_getloadavg() ?: [0, 0, 0]),
            'mem_total_mb' => 4096,
            'mem_used_mb' => 1536,
            'disk_total_gb' => 80,
            'disk_free_gb' => 61,
            'uptime_seconds' => (int) (time() - (filemtime($home) ?: time())),
            'cpu_count' => 2,
            'accounts' => count(glob($home.'/*', GLOB_ONLYDIR) ?: []),
            'services' => [
                ['unit' => 'apache2', 'active' => true, 'state' => 'active'],
                ['unit' => 'nginx', 'active' => true, 'state' => 'active'],
                ['unit' => 'php8.3-fpm', 'active' => true, 'state' => 'active'],
                ['unit' => 'mariadb', 'active' => true, 'state' => 'active'],
                ['unit' => 'atlas-agent', 'active' => true, 'state' => 'active'],
            ],
            'accounts_usage' => [],
        ];
    }

    private function createAccount(array $args): array
    {
        $user = (string) ($args['username'] ?? '');
        $dir = $this->root().'/'.$user.'/public_html';
        @mkdir($dir, 0o750, true);
        @mkdir($this->root().'/'.$user.'/logs', 0o750, true);
        @file_put_contents($dir.'/index.html', $this->welcome($user));

        return ['username' => $user, 'home' => '/home/'.$user, 'docroot' => '/home/'.$user.'/public_html'];
    }

    private function welcome(string $user): string
    {
        return "<!doctype html>\n<title>{$user}</title>\n<h1>It works</h1>\n<p>This site was provisioned by the Atlas panel.</p>\n";
    }

    private function runBackup(array $args): array
    {
        $user = (string) ($args['username'] ?? '');
        $dir = $this->root().'/'.$user;
        $name = now()->format('Ymd-His').'-'.$user.'.tar.gz';
        $target = $dir.'/../'.$name;

        // A real archive is not the point here; the size and name are, because
        // that is what the UI reports.
        @file_put_contents($target, 'sandbox archive');

        return ['archive' => '/var/backups/atlas/'.$name, 'bytes' => is_file($target) ? filesize($target) : 0];
    }

    /** @return array<string, mixed> */
    private function listFiles(array $args): array
    {
        $dir = $this->path((string) ($args['path'] ?? ''));

        if (! is_dir($dir)) {
            throw new AgentException('Directory not found.', 'file.list');
        }

        $entries = [];

        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $full = $dir.'/'.$name;
            $entries[] = [
                'name' => $name,
                'type' => is_dir($full) ? 'dir' : (is_link($full) ? 'link' : 'file'),
                'size' => is_file($full) ? filesize($full) : 0,
                'mode' => substr(sprintf('%o', fileperms($full)), -4),
                'mod_time' => date('c', filemtime($full) ?: time()),
            ];
        }

        usort($entries, fn ($a, $b) => [$b['type'] === 'dir', $a['name']] <=> [$a['type'] === 'dir', $b['name']]);

        return ['path' => $args['path'] ?? '', 'entries' => $entries];
    }

    private function readFile(array $args): array
    {
        $file = $this->path((string) ($args['path'] ?? ''));
        $max = (int) ($args['max_bytes'] ?? 1048576);

        if (! is_file($file)) {
            throw new AgentException('File not found.', 'file.read');
        }

        $content = (string) file_get_contents($file, false, null, 0, $max);

        return [
            'path' => $args['path'] ?? '',
            'content' => $content,
            'bytes' => strlen($content),
            'truncated' => filesize($file) > $max,
        ];
    }

    private function writeFile(array $args): array
    {
        $file = $this->path((string) ($args['path'] ?? ''));
        @mkdir(dirname($file), 0o750, true);

        ($args['append'] ?? false)
            ? file_put_contents($file, (string) ($args['content'] ?? ''), FILE_APPEND)
            : file_put_contents($file, (string) ($args['content'] ?? ''));

        return ['path' => $args['path'] ?? '', 'bytes' => strlen((string) ($args['content'] ?? ''))];
    }

    private function mkdir(array $args): array
    {
        @mkdir($this->path((string) ($args['path'] ?? '')), 0o750, true);

        return ['path' => $args['path'] ?? '', 'created' => true];
    }

    private function deletePath(array $args): array
    {
        $path = $this->path((string) ($args['path'] ?? ''));
        is_dir($path) ? $this->rmdir($path) : @unlink($path);

        return ['path' => $args['path'] ?? '', 'deleted' => true];
    }

    private function renamePath(array $args): array
    {
        @rename($this->path((string) ($args['from'] ?? '')), $this->path((string) ($args['to'] ?? '')));

        return ['from' => $args['from'] ?? '', 'to' => $args['to'] ?? ''];
    }

    private function rmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $dir.'/'.$name;
            is_dir($full) ? $this->rmdir($full) : @unlink($full);
        }

        @rmdir($dir);
    }

    /** Maps /home/<user>/... into the sandbox root. */
    private function path(string $virtual): string
    {
        $parts = array_values(array_filter(explode('/', $virtual), fn ($p) => $p !== ''));

        if (count($parts) < 2 || $parts[0] !== 'home') {
            throw new AgentException('Path is outside the account.', 'file');
        }

        return $this->root().'/'.implode('/', array_slice($parts, 1));
    }

    private function root(): string
    {
        $root = $this->root ?: storage_path('app/sandbox/home');

        if (! is_dir($root)) {
            @mkdir($root, 0o750, true);
        }

        return rtrim($root, '/');
    }

    /** @return array<string, mixed> */
    private function ok(array $result): array
    {
        return ['sandbox' => true] + $result;
    }

    /** @return array<string, mixed> */
    private function writeRedirect(array $args): array
    {
        $dir = $this->root().'/.redirects';
        @mkdir($dir, 0o750, true);
        @file_put_contents(
            $dir.'/'.($args['domain'] ?? 'unknown').'.conf',
            ($args['source_path'] ?? '/').' '.$args['status_code'].' '.($args['target_url'] ?? '')."\n",
            FILE_APPEND,
        );

        return $this->ok(['domain' => $args['domain'] ?? '', 'written' => true]);
    }

    /** @return array<string, mixed> */
    private function dkim(array $args): array
    {
        $domain = (string) ($args['domain'] ?? '');

        return $this->ok([
            'domain' => $domain,
            'selector' => 'atlas',
            'record_name' => 'atlas._domainkey.'.$domain,
            'record_value' => 'v=DKIM1; k=rsa; p=SANDBOXKEYNOTREAL',
        ]);
    }

    /** @return array<string, mixed> */
    private function csr(array $args): array
    {
        $domain = (string) ($args['domain'] ?? '');
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = $key ? openssl_csr_new(['commonName' => $domain], $key) : null;

        openssl_csr_export($csr ?: null, $pem);

        return $this->ok([
            'common_name' => $domain,
            'csr_pem' => $pem ?: "-----BEGIN CERTIFICATE REQUEST-----\nSANDBOX\n-----END CERTIFICATE REQUEST-----",
            'key_path' => '/home/sandbox/ssl/'.$domain.'.key',
        ]);
    }

    /** @return array<string, mixed> */
    private function extensions(array $args): array
    {
        $names = ['Core', 'ctype', 'curl', 'date', 'dom', 'fileinfo', 'filter', 'gd',
            'hash', 'intl', 'json', 'libxml', 'mbstring', 'openssl', 'pcre', 'PDO',
            'pdo_mysql', 'pdo_sqlite', 'Phar', 'posix', 'random', 'readline', 'Reflection',
            'session', 'SimpleXML', 'sodium', 'SPL', 'sqlite3', 'standard', 'tokenizer',
            'xml', 'xmlreader', 'xmlwriter', 'zip', 'zlib'];

        return ['php_version' => $args['php_version'] ?? '', 'extensions' => array_map(
            fn ($n) => ['name' => $n, 'enabled' => true],
            $names,
        )];
    }

    /** @return array<string, mixed> */
    private function installApp(array $args): array
    {
        $docroot = (string) ($args['docroot'] ?? '');
        $path = trim((string) ($args['path'] ?? ''), '/');
        $target = rtrim($docroot, '/').($path === '' ? '' : '/'.$path);

        try {
            $dir = $this->path($target);
            @mkdir($dir, 0o750, true);
            @file_put_contents($dir.'/index.php', "<?php // installed by the Atlas sandbox\n");
        } catch (AgentException) {
            // A docroot outside /home simply is not materialised in the sandbox.
        }

        return $this->ok([
            'slug' => $args['slug'] ?? '',
            'path' => $target,
            'version' => $args['version'] ?? '',
            'url' => 'https://'.($args['domain'] ?? '').'/'.$path,
        ]);
    }

    /** @return array<string, mixed> */
    private function bandwidth(array $args): array
    {
        $days = [];
        $total = 0;

        for ($i = 29; $i >= 0; $i--) {
            // A deterministic shape so the chart is stable between requests.
            $mb = 40 + (int) (25 * sin($i / 3.4)) + ($i % 7) * 3;
            $days[] = [
                'day' => now()->subDays($i)->toDateString(),
                'http_mb' => max(0, $mb),
                'mail_mb' => max(0, (int) ($mb / 8)),
                'ftp_mb' => max(0, (int) ($mb / 20)),
            ];
            $total += $mb;
        }

        return ['username' => $args['username'] ?? '', 'days' => $days, 'total_mb' => $total];
    }

    /** @return array<string, mixed> */
    private function diskUsage(array $args): array
    {
        $virtual = (string) ($args['path'] ?? '');
        $children = [];

        try {
            $dir = $this->path($virtual);
            foreach (scandir($dir) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $full = $dir.'/'.$name;
                $children[] = [
                    'name' => $name,
                    'type' => is_dir($full) ? 'dir' : 'file',
                    'size_mb' => is_dir($full) ? 12 : max(1, (int) ((filesize($full) ?: 0) / 1024)),
                ];
            }
        } catch (AgentException) {
            $children = [];
        }

        return [
            'path' => $virtual,
            'total_mb' => 40960,
            'used_mb' => array_sum(array_column($children, 'size_mb')),
            'children' => $children,
        ];
    }

    public function randomPassword(): string
    {
        return Str::password(16, symbols: false);
    }

    // -----------------------------------------------------------------------
    // file manager and ssh
    //
    // Every method here reaches the sandbox filesystem through path(), which
    // refuses anything that does not start with /home/<one segment>/ — the same
    // boundary the real agent enforces with a jail. Where a real operation
    // would need root (chmod, tar, unzip) the arm above reports
    // `simulated => true` rather than pretending it happened.
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function uploadFile(array $args): array
    {
        $target = $this->path((string) ($args['path'] ?? ''));

        if (is_dir($target)) {
            throw new AgentException('That path is a folder.', 'file.upload');
        }

        if (file_exists($target) && ! ($args['overwrite'] ?? true)) {
            throw new AgentException(basename($target).' already exists.', 'file.upload');
        }

        $bytes = base64_decode((string) ($args['content'] ?? ''), true);

        if ($bytes === false) {
            throw new AgentException('The upload was not valid base64.', 'file.upload');
        }

        @mkdir(dirname($target), 0o750, true);

        if (@file_put_contents($target, $bytes) === false) {
            throw new AgentException('The sandbox could not write that file.', 'file.upload');
        }

        return $this->ok([
            'name' => basename($target),
            'path' => $args['path'] ?? '',
            'bytes' => strlen($bytes),
        ]);
    }

    /** @return array<string, mixed> */
    private function downloadFile(array $args): array
    {
        $target = $this->path((string) ($args['path'] ?? ''));

        if (! is_file($target)) {
            throw new AgentException('File not found.', 'file.download');
        }

        $limit = (int) ($args['max_bytes'] ?? 33554432);
        $size = (int) filesize($target);
        $bytes = (string) file_get_contents($target, false, null, 0, $limit + 1);

        $truncated = strlen($bytes) > $limit;
        if ($truncated) {
            $bytes = substr($bytes, 0, $limit);
        }

        return $this->ok([
            'content' => base64_encode($bytes),
            'bytes' => $size,
            'truncated' => $truncated,
            'mime_type' => $this->mimeOf($target),
        ]);
    }

    private function mimeOf(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'gz', 'tgz' => 'application/gzip',
            'css' => 'text/css',
            'js' => 'text/javascript',
            'php' => 'text/x-php',
            'html', 'htm' => 'text/html',
            'txt', 'log', 'md', 'conf', 'ini' => 'text/plain',
            default => 'application/octet-stream',
        };
    }

    /** @return array<string, mixed> */
    private function copyPath(array $args): array
    {
        $from = $this->path((string) ($args['from'] ?? ''));
        $to = $this->path((string) ($args['to'] ?? ''));

        if (! file_exists($from)) {
            throw new AgentException('That path does not exist.', 'file.copy');
        }

        if (is_dir($to)) {
            $to = rtrim($to, '/').'/'.basename($from);
        }

        if (file_exists($to) && ! ($args['overwrite'] ?? false)) {
            throw new AgentException(basename($to).' already exists.', 'file.copy');
        }

        if (is_dir($from)) {
            $this->copyTree($from, $to);

            return $this->ok(['from' => $args['from'], 'to' => $args['to'], 'copied' => 1]);
        }

        @mkdir(dirname($to), 0o750, true);

        if (! @copy($from, $to)) {
            throw new AgentException('The sandbox could not copy that file.', 'file.copy');
        }

        return $this->ok(['from' => $args['from'], 'to' => $args['to'], 'copied' => 1]);
    }

    private function copyTree(string $from, string $to): void
    {
        @mkdir($to, 0o750, true);

        foreach (scandir($from) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $src = $from.'/'.$name;
            $dst = $to.'/'.$name;

            if (is_dir($src)) {
                $this->copyTree($src, $dst);
            } else {
                @copy($src, $dst);
            }
        }
    }

    /** @return array<string, mixed> */
    private function searchFiles(array $args): array
    {
        $root = $this->path((string) ($args['path'] ?? ''));
        $needle = strtolower((string) ($args['query'] ?? ''));
        $limit = (int) ($args['limit'] ?? 200);

        if ($needle === '') {
            throw new AgentException('Search for a name, not nothing.', 'file.search');
        }

        if (strpbrk($needle, '*?[]') !== false) {
            throw new AgentException('Search matches plain names, not patterns.', 'file.search');
        }

        if (! is_dir($root)) {
            throw new AgentException('That folder does not exist.', 'file.search');
        }

        $hits = [];
        $walk = function (string $dir) use (&$walk, &$hits, $needle, $limit): void {
            foreach (scandir($dir) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                if (count($hits) >= $limit) {
                    return;
                }

                $path = $dir.'/'.$name;

                if (str_contains(strtolower($name), $needle)) {
                    $hits[] = [
                        'path' => '/home/'.ltrim(substr($path, strlen($this->root())), '/'),
                        'type' => is_dir($path) ? 'dir' : 'file',
                        'size' => is_dir($path) ? 0 : (int) filesize($path),
                        'mod_time' => date('c', (int) filemtime($path)),
                    ];
                }

                if (is_dir($path) && ! is_link($path)) {
                    $walk($path);
                }
            }
        };

        $walk($root);

        return $this->ok(['hits' => $hits, 'truncated' => count($hits) >= $limit]);
    }

    /** @return array<string, mixed> */
    private function statPaths(array $args): array
    {
        $sizes = [];

        foreach ((array) ($args['paths'] ?? []) as $virtual) {
            $path = $this->path((string) $virtual);
            $total = 0;

            if (is_file($path)) {
                $total = (int) filesize($path);
            } elseif (is_dir($path)) {
                $walk = function (string $dir) use (&$walk, &$total): void {
                    foreach (scandir($dir) ?: [] as $name) {
                        if ($name === '.' || $name === '..') {
                            continue;
                        }
                        $p = $dir.'/'.$name;
                        if (is_dir($p)) {
                            $walk($p);
                        } else {
                            $total += (int) filesize($p);
                        }
                    }
                };
                $walk($path);
            }

            $sizes[] = ['path' => (string) $virtual, 'bytes' => $total];
        }

        return $this->ok(['sizes' => $sizes]);
    }

    /** The sandbox trash: a real folder, so delete-and-restore is exercised. */
    private function trashRoot(): string
    {
        return $this->root().'/.atlas-trash';
    }

    /** @return array<string, mixed> */
    private function trashPath(array $args): array
    {
        $src = $this->path((string) ($args['path'] ?? ''));

        if (! file_exists($src)) {
            throw new AgentException('That path does not exist.', 'file.trash');
        }

        if (basename($src) === '.atlas-trash') {
            throw new AgentException('The trash cannot be moved into itself.', 'file.trash');
        }

        $trash = $this->trashRoot();
        @mkdir($trash, 0o750, true);

        $rel = ltrim(substr($src, strlen($this->root())), '/');
        $entry = date('Ymd-His').'__'.rtrim(strtr(base64_encode($rel), '+/', '-_'), '=');
        $target = $trash.'/'.$entry;

        if (! @rename($src, $target)) {
            throw new AgentException('The sandbox could not move that item to the trash.', 'file.trash');
        }

        return $this->ok(['name' => basename($src), 'path' => '/home/'.basename($this->root()).'/.atlas-trash/'.$entry]);
    }

    /** @return array<string, mixed> */
    private function restorePath(array $args): array
    {
        $entry = basename((string) ($args['path'] ?? ''));
        $src = $this->trashRoot().'/'.$entry;

        if (! file_exists($src)) {
            throw new AgentException('That trash entry is gone.', 'file.restore');
        }

        $idx = strpos($entry, '__');

        if ($idx === false) {
            throw new AgentException('That trash entry has no original path.', 'file.restore');
        }

        $rel = base64_decode(strtr(substr($entry, $idx + 2), '-_', '+/'), true);

        if ($rel === false || $rel === '') {
            throw new AgentException('That trash entry is corrupt.', 'file.restore');
        }

        $target = $this->root().'/'.ltrim($rel, '/');

        if (file_exists($target)) {
            throw new AgentException($rel.' already exists; rename or remove it before restoring.', 'file.restore');
        }

        @mkdir(dirname($target), 0o750, true);

        if (! @rename($src, $target)) {
            throw new AgentException('The sandbox could not restore that item.', 'file.restore');
        }

        return $this->ok(['restored' => '/home/'.basename($this->root()).'/'.$rel]);
    }

    /** @return array<string, mixed> */
    private function listTrash(array $args): array
    {
        $entries = [];
        $trash = $this->trashRoot();

        foreach (is_dir($trash) ? (scandir($trash) ?: []) : [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $display = $name;
            $idx = strpos($name, '__');

            if ($idx !== false) {
                $decoded = base64_decode(strtr(substr($name, $idx + 2), '-_', '+/'), true);
                if ($decoded !== false && $decoded !== '') {
                    $display = $decoded;
                }
            }

            $entries[] = [
                'name' => $display,
                'path' => '/home/'.basename($this->root()).'/.atlas-trash/'.$name,
                'deleted_at' => date('c', (int) filemtime($trash.'/'.$name)),
            ];
        }

        usort($entries, fn ($a, $b) => strcmp($b['deleted_at'], $a['deleted_at']));

        return $this->ok(['entries' => $entries]);
    }

    // ---- ssh -------------------------------------------------------------

    private function sshRoot(): string
    {
        $dir = $this->root().'/.ssh';
        @mkdir($dir, 0o700, true);

        return $dir;
    }

    private function keyIndexPath(): string
    {
        return $this->root().'/.ssh/.sandbox-keys.json';
    }

    /** @return array<string, array<string, mixed>> */
    private function keyIndex(): array
    {
        $raw = @file_get_contents($this->keyIndexPath());

        return $raw ? (json_decode($raw, true) ?: []) : [];
    }

    /** @param  array<string, array<string, mixed>>  $index */
    private function saveKeyIndex(array $index): void
    {
        @file_put_contents($this->keyIndexPath(), json_encode($index, JSON_PRETTY_PRINT));
    }

    /**
     * A sandbox "keypair": shaped like a real one so the screen and the tests
     * are exercised, and clearly marked as not a key so nobody mistakes it for
     * one. The real agent shells out to ssh-keygen.
     */
    private function fakeKey(string $name, string $type, string $comment): array
    {
        $random = bin2hex(random_bytes(24));

        return [
            'name' => $name,
            'type' => $type === 'rsa' ? 'ssh-rsa' : 'ssh-ed25519',
            'bits' => $type === 'rsa' ? 4096 : 256,
            'comment' => $comment,
            'public' => ($type === 'rsa' ? 'ssh-rsa ' : 'ssh-ed25519 ').'SANDBOX-NOT-A-REAL-KEY'.$random.' '.$comment,
            'fingerprint' => 'SHA256:'.substr(hash('sha256', $random), 0, 43),
            'private' => "-----BEGIN SANDBOX PRIVATE KEY-----\nnot a key: this is the development sandbox\n".$random."\n-----END SANDBOX PRIVATE KEY-----\n",
            'authorized' => false,
            'created_at' => date('c'),
        ];
    }

    /** @return array<string, mixed> */
    private function generateKey(array $args): array
    {
        $name = (string) ($args['name'] ?? '');
        $index = $this->keyIndex();

        if (isset($index[$name])) {
            throw new AgentException('A key called "'.$name.'" already exists.', 'ssh.key.generate');
        }

        $key = $this->fakeKey($name, (string) ($args['type'] ?? 'ed25519'), (string) ($args['comment'] ?? ''));

        @file_put_contents($this->sshRoot().'/'.$name, $key['private']);
        @chmod($this->sshRoot().'/'.$name, 0o600);
        @file_put_contents($this->sshRoot().'/'.$name.'.pub', $key['public']."\n");

        $index[$name] = $key;
        $this->saveKeyIndex($index);

        return $this->ok($this->publicShape($key));
    }

    /** @return array<string, mixed> */
    private function importKey(array $args): array
    {
        $name = (string) ($args['name'] ?? '');
        $public = trim((string) ($args['public'] ?? ''));
        $index = $this->keyIndex();

        if (isset($index[$name])) {
            throw new AgentException('A key called "'.$name.'" already exists.', 'ssh.key.import');
        }

        $fields = preg_split('/\s+/', $public) ?: [];

        $key = [
            'name' => $name,
            'type' => $fields[0] ?? 'ssh-ed25519',
            'bits' => str_starts_with($fields[0] ?? '', 'ssh-rsa') ? 4096 : 256,
            'comment' => implode(' ', array_slice($fields, 2)),
            'public' => $public,
            'fingerprint' => 'SHA256:'.substr(hash('sha256', $public), 0, 43),
            'private' => null,
            'authorized' => false,
            'created_at' => date('c'),
        ];

        @file_put_contents($this->sshRoot().'/'.$name.'.pub', $public."\n");

        $index[$name] = $key;
        $this->saveKeyIndex($index);

        return $this->ok($this->publicShape($key));
    }

    /** @param  array<string, mixed>  $key */
    private function publicShape(array $key): array
    {
        unset($key['private']);

        return $key;
    }

    /** @return array<string, mixed> */
    private function listKeys(array $args): array
    {
        $keys = array_values(array_map(fn ($k) => $this->publicShape($k), $this->keyIndex()));

        usort($keys, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        return $this->ok(['keys' => $keys]);
    }

    /** @return array<string, mixed> */
    private function authorizeKey(array $args, bool $authorize): array
    {
        $name = (string) ($args['name'] ?? '');
        $index = $this->keyIndex();

        if (! isset($index[$name])) {
            throw new AgentException('Key "'.$name.'" does not exist.', 'ssh.key.authorize');
        }

        $index[$name]['authorized'] = $authorize;
        $this->saveKeyIndex($index);
        $this->rewriteAuthorizedKeys($index);

        return $this->ok(['name' => $name, 'authorized' => $authorize]);
    }

    /**
     * authorized_keys is written in one place so deleting a key cannot leave it
     * authorised — the bug where a deleted key still logs in.
     *
     * @param  array<string, array<string, mixed>>  $index
     */
    private function rewriteAuthorizedKeys(array $index): void
    {
        $lines = [];

        foreach ($index as $key) {
            if (! empty($key['authorized']) && ! empty($key['public'])) {
                $lines[] = (string) $key['public'];
            }
        }

        @file_put_contents(
            $this->sshRoot().'/authorized_keys',
            $lines === [] ? '' : implode("\n", $lines)."\n"
        );
        @chmod($this->sshRoot().'/authorized_keys', 0o600);
    }

    /** @return array<string, mixed> */
    private function deleteKey(array $args): array
    {
        $name = (string) ($args['name'] ?? '');
        $index = $this->keyIndex();

        if (! isset($index[$name])) {
            throw new AgentException('Key "'.$name.'" does not exist.', 'ssh.key.delete');
        }

        unset($index[$name]);
        $this->saveKeyIndex($index);

        @unlink($this->sshRoot().'/'.$name);
        @unlink($this->sshRoot().'/'.$name.'.pub');

        // Take the deleted key out of authorized_keys, or it would still log in.
        $this->rewriteAuthorizedKeys($index);

        return $this->ok(['name' => $name, 'deleted' => true]);
    }
}
