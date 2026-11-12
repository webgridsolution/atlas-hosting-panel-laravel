<?php

namespace Tests\Support;

use App\Domain\Agent\Agent;
use App\Domain\Agent\AgentException;

/**
 * Records every operation the panel dispatches and returns plausible results.
 * Tests assert on the recorded ops, which is exactly what a real agent would
 * receive over the socket.
 */
class FakeAgent implements Agent
{
    /** @var array<int, array{op: string, args: array<string, mixed>}> */
    public array $calls = [];

    public bool $healthy = true;

    public ?string $failOn = null;

    public function call(string $op, array $args = []): array
    {
        $this->calls[] = ['op' => $op, 'args' => $args];

        if ($this->failOn === $op) {
            throw new AgentException('simulated failure', $op, 422);
        }

        return match ($op) {
            'system.stats' => [
                'hostname' => 'test-host', 'load_avg' => [0.4, 0.5, 0.6],
                'mem_total_mb' => 4096, 'mem_used_mb' => 1024,
                'disk_total_gb' => 80, 'disk_free_gb' => 60,
                'uptime_seconds' => 86400, 'cpu_count' => 2, 'accounts' => 1,
                'services' => [['unit' => 'apache2', 'active' => true, 'state' => 'active']],
                'accounts_usage' => [],
            ],
            'ssl.issue' => ['domain' => $args['domain'] ?? '', 'issuer' => 'Fake CA', 'expires_at' => now()->addDays(90)->toIso8601String()],
            'backup.run' => ['archive' => '/var/backups/atlas/x.tar.gz', 'bytes' => 2048],
            'file.list' => ['path' => $args['path'] ?? '', 'entries' => [
                [
                    'name' => 'public_html',
                    'path' => rtrim((string) ($args['path'] ?? '/home/acme'), '/').'/public_html',
                    'type' => 'dir', 'size' => 0, 'mode' => '0750',
                    'mod_time' => now()->toIso8601String(),
                ],
            ]],
            'file.read' => ['path' => $args['path'] ?? '', 'content' => '<?php echo "hi";', 'bytes' => 16, 'truncated' => false],

            // Hosting operations whose results the panel stores or shows.
            'bandwidth.report' => ['days' => [
                ['day' => now()->subDays(2)->toDateString(), 'http_mb' => 120, 'mail_mb' => 8, 'ftp_mb' => 2],
                ['day' => now()->subDay()->toDateString(), 'http_mb' => 90, 'mail_mb' => 4, 'ftp_mb' => 1],
                ['day' => now()->toDateString(), 'http_mb' => 140, 'mail_mb' => 6, 'ftp_mb' => 3],
            ], 'total_mb' => 374],
            'disk.usage' => [
                'used_mb' => 480, 'limit_mb' => 10240, 'inodes_used' => 2400, 'inodes_limit' => 100000,
                'entries' => [
                    ['name' => 'public_html', 'type' => 'dir', 'size_mb' => 300],
                    ['name' => 'mail', 'type' => 'dir', 'size_mb' => 120],
                    ['name' => 'logs', 'type' => 'dir', 'size_mb' => 60],
                ],
            ],
            'mail.dkim.ensure' => [
                'record_name' => 'atlas._domainkey.'.($args['domain'] ?? 'example.test'),
                'record_value' => 'v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQ',
            ],
            'php.extensions' => ['extensions' => [
                ['name' => 'curl', 'enabled' => true],
                ['name' => 'gd', 'enabled' => true],
                ['name' => 'intl', 'enabled' => true],
                ['name' => 'redis', 'enabled' => false],
            ]],
            'ssl.csr' => [
                'common_name' => 'example.test',
                'csr_pem' => "-----BEGIN CERTIFICATE REQUEST-----\nZmFrZQ==\n-----END CERTIFICATE REQUEST-----",
                'key_path' => '/etc/atlas/ssl/example.test/key.pem',
            ],
            'ssl.info' => ['common_name' => 'example.test', 'issuer' => 'Fake CA', 'expires_at' => now()->addDays(90)->toIso8601String()],
            'app.install' => [
                'url' => 'https://'.($args['domain'] ?? 'example.test').'/'.($args['path'] ?? ''),
                'version' => '6.7',
                'db_name' => ($args['db_name'] ?? 'app_db'),
                'db_user' => ($args['db_user'] ?? 'app_user'),
            ],
            'app.info' => ['version' => '6.8'],
            'git.pull' => ['output' => 'Already up to date.', 'commit' => 'abc1234'],

            // ---- file manager and ssh -----------------------------------
            'file.upload' => [
                'name' => basename((string) ($args['path'] ?? 'x')),
                'path' => $args['path'] ?? '',
                'bytes' => strlen((string) base64_decode((string) ($args['content'] ?? ''), true)),
            ],
            'file.download' => [
                'content' => $args['content'] ?? base64_encode('hello world'),
                'bytes' => (int) ($args['bytes'] ?? 11),
                'truncated' => (bool) ($args['truncated'] ?? false),
                'mime_type' => (string) ($args['mime_type'] ?? 'text/plain'),
            ],
            'file.copy' => ['from' => $args['from'] ?? '', 'to' => $args['to'] ?? '', 'copied' => 1],
            'file.chmod' => ['path' => $args['path'] ?? '', 'mode' => $args['mode'] ?? '', 'changed' => 1],
            'file.compress' => ['archive' => $args['archive'] ?? '', 'files' => count($args['paths'] ?? [])],
            'file.extract' => ['files' => 3],
            'file.search' => ['hits' => [
                ['path' => '/home/acme/public_html/wp-config.php', 'type' => 'file', 'size' => 3120, 'mod_time' => now()->toIso8601String()],
            ], 'truncated' => false],
            'file.stat' => ['sizes' => array_map(
                fn ($p) => ['path' => $p, 'bytes' => 4096],
                (array) ($args['paths'] ?? [])
            )],
            'file.trash' => ['name' => basename((string) ($args['path'] ?? 'x')), 'path' => '/home/acme/.atlas-trash/20260101-000000__x'],
            'file.restore' => ['restored' => '/home/acme/public_html/old.txt'],
            'file.trash.list' => ['entries' => [
                ['name' => 'public_html/old.txt', 'path' => '/home/acme/.atlas-trash/20260101-000000__cHVibGljX2h0bWwvb2xkLnR4dA', 'deleted_at' => now()->toIso8601String()],
            ]],

            'ssh.key.generate' => [
                'name' => $args['name'] ?? '', 'type' => 'ssh-ed25519', 'bits' => 256,
                'comment' => $args['comment'] ?? '', 'public' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeKeyForTests000000000000000000000000 acme@atlas',
                'fingerprint' => 'SHA256:fakefingerprintfortests', 'has_private' => true,
                'authorized' => false, 'created_at' => now()->toIso8601String(),
            ],
            'ssh.key.import' => [
                'name' => $args['name'] ?? '', 'type' => 'ssh-rsa', 'bits' => 4096,
                'comment' => 'imported', 'public' => $args['public'] ?? '',
                'fingerprint' => 'SHA256:importedfingerprintfortests', 'has_private' => false,
                'authorized' => false, 'created_at' => now()->toIso8601String(),
            ],
            'ssh.key.list' => ['keys' => [
                ['name' => 'id_ed25519', 'type' => 'ssh-ed25519', 'bits' => 256, 'comment' => 'laptop',
                    'public' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFake acme@atlas',
                    'fingerprint' => 'SHA256:fakefingerprintfortests', 'has_private' => true,
                    'authorized' => true, 'created_at' => now()->toIso8601String()],
            ]],
            'ssh.key.authorize' => ['name' => $args['name'] ?? '', 'authorized' => true],
            'ssh.key.deauthorize' => ['name' => $args['name'] ?? '', 'authorized' => false],
            'ssh.key.delete' => ['name' => $args['name'] ?? '', 'deleted' => true],
            'ssh.access.set' => ['username' => $args['username'] ?? '', 'enabled' => (bool) ($args['enabled'] ?? false)],
            'ssh.info' => [
                'enabled' => true, 'port' => 22,
                'host_keys' => [
                    ['type' => 'ed25519', 'fingerprint' => 'SHA256:hosted25519fingerprint'],
                    ['type' => 'rsa', 'fingerprint' => 'SHA256:hostrsafingerprint'],
                ],
                'algorithms' => ['ed25519', 'rsa'],
            ],

            default => ['ok' => true],
        };
    }

    public function healthy(): bool
    {
        return $this->healthy;
    }

    /** @return array<int, string> */
    public function ops(): array
    {
        return array_column($this->calls, 'op');
    }

    public function called(string $op): bool
    {
        return in_array($op, $this->ops(), true);
    }

    /** @return array<string, mixed> */
    public function argsFor(string $op): array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['op'] === $op) {
                return $call['args'];
            }
        }

        return [];
    }
}
