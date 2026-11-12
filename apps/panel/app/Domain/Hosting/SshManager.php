<?php

namespace App\Domain\Hosting;

use App\Domain\Agent\Audit;
use App\Models\Account;
use RuntimeException;

/**
 * SSH key management and shell access for one account.
 *
 * The rules in this file are a mirror of the agent's validators
 * (`internal/ops/file_ssh_validate.go`). They are duplicated on purpose: the
 * panel has to explain a refusal before a request is signed, and the agent has
 * to refuse the same thing on its own because it cannot trust the panel. The
 * cost is that the two must be changed together — which is why the messages
 * below and there are worded identically.
 *
 * The panel never stores a private key. It asks the agent to generate one, the
 * key lives in ~/.ssh, and the customer downloads it once through an action
 * this class performs and then lets go of.
 */
class SshManager
{
    /** Names ssh itself reads. A customer cannot take one of these. */
    public const RESERVED_NAMES = [
        'authorized_keys',
        'config',
        'known_hosts',
        'known_hosts.old',
        'environment',
        'rc',
    ];

    /** Key types OpenSSH accepts in authorized_keys, including FIDO tokens. */
    public const KEY_TYPES = [
        'ssh-ed25519',
        'ssh-rsa',
        'ssh-dss',
        'ecdsa-sha2-nistp256',
        'ecdsa-sha2-nistp384',
        'ecdsa-sha2-nistp521',
        'sk-ssh-ed25519@openssh.com',
        'sk-ecdsa-sha2-nistp256@openssh.com',
    ];

    public function __construct(private readonly Audit $audit) {}

    public function nameIsValid(string $name): bool
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $name)) {
            return false;
        }

        if (in_array(strtolower($name), self::RESERVED_NAMES, true)) {
            return false;
        }

        return ! str_ends_with(strtolower($name), '.pub');
    }

    public function assertName(string $name): void
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $name)) {
            throw new RuntimeException('A key name uses letters, digits, dot, underscore and hyphen only.');
        }

        if (in_array(strtolower($name), self::RESERVED_NAMES, true)) {
            throw new RuntimeException('That name is reserved by ssh. Choose another.');
        }

        if (str_ends_with(strtolower($name), '.pub')) {
            throw new RuntimeException('A key name must not end in .pub — that half is written for you.');
        }
    }

    /**
     * Check a pasted public key before it is imported. Returns the refusal
     * message, or null when the key is usable.
     */
    public function publicKeyProblem(string $public): ?string
    {
        $public = trim($public);

        if ($public === '') {
            return 'A public key is required.';
        }

        if (strlen($public) > 8192) {
            return 'That public key is too long.';
        }

        if (str_contains($public, "\n") || str_contains($public, "\r") || str_contains($public, "\0")) {
            return 'A public key is one line.';
        }

        $fields = preg_split('/\s+/', $public) ?: [];

        if (count($fields) < 2) {
            return 'That is not a public key: expected "ssh-ed25519 AAAA…" or similar.';
        }

        if (! in_array($fields[0], self::KEY_TYPES, true)) {
            return 'Key type "'.$fields[0].'" is not one OpenSSH accepts.';
        }

        if (strlen($fields[1]) < 32) {
            return 'The key body is the wrong length.';
        }

        return null;
    }

    /**
     * Generate or import, whichever the form supplied.
     *
     * One route serves both because the dialog is one dialog: a customer who has
     * a private key on their laptop imports the public half; one who does not
     * generates a pair here. The difference is a single field.
     */
    public function store(Account $account, array $data): array
    {
        $name = (string) ($data['name'] ?? '');
        $this->assertName($name);

        $public = trim((string) ($data['public'] ?? ''));

        if ($public !== '') {
            $problem = $this->publicKeyProblem($public);
            if ($problem !== null) {
                throw new RuntimeException($problem);
            }

            return $this->audit->dispatch('ssh.key.import', [
                'username' => $account->username,
                'name' => $name,
                'public' => $public,
            ], $account);
        }

        $type = (string) ($data['type'] ?? 'ed25519');

        if (! in_array($type, ['ed25519', 'rsa'], true)) {
            throw new RuntimeException('Choose ed25519 or rsa.');
        }

        $bits = $type === 'rsa' ? (int) ($data['bits'] ?? 4096) : 0;

        if ($type === 'rsa' && ($bits < 2048 || $bits > 8192)) {
            throw new RuntimeException('An RSA key must be between 2048 and 8192 bits.');
        }

        $args = [
            'username' => $account->username,
            'name' => $name,
            'type' => $type,
            'comment' => (string) ($data['comment'] ?? '') ?: ($account->username.'@atlas'),
        ];

        if ($type === 'rsa') {
            $args['bits'] = $bits;
        }

        return $this->audit->dispatch('ssh.key.generate', $args, $account);
    }

    /** @return array<int, array<string, mixed>> */
    public function keys(Account $account): array
    {
        $result = $this->audit->dispatch('ssh.key.list', [
            'username' => $account->username,
        ], $account);

        return $result['keys'] ?? [];
    }

    public function authorize(Account $account, string $name): array
    {
        $this->assertName($name);

        return $this->audit->dispatch('ssh.key.authorize', [
            'username' => $account->username,
            'name' => $name,
        ], $account);
    }

    public function deauthorize(Account $account, string $name): array
    {
        $this->assertName($name);

        return $this->audit->dispatch('ssh.key.deauthorize', [
            'username' => $account->username,
            'name' => $name,
        ], $account);
    }

    public function remove(Account $account, string $name): array
    {
        $this->assertName($name);

        return $this->audit->dispatch('ssh.key.delete', [
            'username' => $account->username,
            'name' => $name,
        ], $account);
    }

    /**
     * The private half, for the one download a customer gets.
     *
     * It is read through the agent's file.download, which is jailed to the home,
     * and returned to the browser with `no-store` so it does not sit in a proxy
     * cache.
     */
    public function privateKey(Account $account, string $name): string
    {
        $this->assertName($name);

        $result = $this->audit->dispatch('file.download', [
            'username' => $account->username,
            'path' => $account->home().'/.ssh/'.$name,
            'max_bytes' => 65536,
        ], $account);

        $bytes = base64_decode((string) ($result['content'] ?? ''), true);

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('That key has no private half on this server.');
        }

        return $bytes;
    }

    /**
     * Switch shell logins on or off.
     *
     * Turning access off does not delete anything: the keys stay, the folder
     * stays, and turning it back on is a single click.
     */
    public function setAccess(Account $account, bool $enabled): array
    {
        $result = $this->audit->dispatch('ssh.access.set', [
            'username' => $account->username,
            'enabled' => $enabled,
        ], $account);

        $account->forceFill([
            'ssh_enabled' => $enabled,
            'ssh_enabled_at' => $enabled ? now() : null,
        ])->save();

        return $result;
    }

    /** @return array<string, mixed> */
    public function serverInfo(): array
    {
        try {
            return $this->audit->dispatch('ssh.info', []);
        } catch (\Throwable) {
            // The host keys are decoration; a screen that cannot read them
            // should still show the keys and the toggle.
            return ['port' => 22, 'host_keys' => [], 'algorithms' => [], 'enabled' => true];
        }
    }
}
