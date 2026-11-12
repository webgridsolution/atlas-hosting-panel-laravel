<?php

namespace App\Domain\Licensing;

use App\Models\Licence;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verifies the signed licence token and decides what the panel is allowed to
 * do. The rules are deliberately boring:
 *
 *   active      everything works
 *   grace       expiry passed but inside the grace window: everything works,
 *               the UI says so, and the operator has time to renew
 *   read_only   no writing, no provisioning — but existing sites keep serving
 *               and an admin can still log in to fix the licence
 *
 * No state deletes customer data. Expiry is never a data-loss event.
 */
class LicenseManager
{
    private const PREFIX = 'atlas-lic-v1.';

    /** Grace period after expiry, in days, from the WHM setting or config. */
    public function graceDays(): int
    {
        return (int) (Setting::get('licence_grace_days') ?? config('atlas.central.grace_days', 14));
    }

    public function current(): Licence
    {
        return Licence::current();
    }

    /** @return array{state: string, reason: string, days_left: int|null, features: array<string, bool>} */
    public function evaluate(): array
    {
        return Cache::remember('licence:evaluate', 60, fn () => $this->compute());
    }

    /** @return array{state: string, reason: string, days_left: int|null, features: array<string, bool>} */
    private function compute(): array
    {
        $licence = $this->current();
        $token = $licence->token ?: Setting::get('licence_token');

        if (! $token) {
            return $this->result(Licence::UNLICENSED, 'No licence has been activated on this server.');
        }

        $claim = $this->verify($token);

        if (is_string($claim)) {
            return $this->result(Licence::READ_ONLY, $claim);
        }

        $features = $this->features($claim);
        $exp = (int) ($claim['exp'] ?? 0);
        $now = time();

        // The vendor's verdict comes first: a signed `sts` is the authority on
        // whether this licence may be used, and the local clock is only a
        // backstop for the obvious case (the token's own expiry passing while
        // the panel is offline).
        $status = strtolower((string) ($claim['sts'] ?? ''));

        if (in_array($status, ['revoked', 'suspended'], true) || ! empty($claim['revoked'])) {
            $why = $status !== '' ? $status : 'revoked';

            return $this->result(
                Licence::READ_ONLY,
                'The vendor marked this licence '.$why.'. Existing websites keep serving; changes are disabled.',
                $features,
            );
        }

        if ($exp > 0 && $now > $exp) {
            $graceEnds = $exp + $this->graceDays() * 86400;

            if ($now <= $graceEnds) {
                $daysLeft = (int) ceil(($graceEnds - $now) / 86400);

                return $this->result(
                    Licence::GRACE,
                    'The licence expired on '.date('j M Y', $exp).". Hosting keeps running for {$daysLeft} more day(s); renew to avoid read-only mode.",
                    $features,
                    $daysLeft,
                );
            }

            return $this->result(
                Licence::READ_ONLY,
                'The licence expired and the grace period is over. Existing websites keep serving; changes are disabled.',
                $features,
            );
        }

        // A clock that jumps backwards would otherwise extend a licence for
        // ever; treat it the same as an expired token.
        $lastCheck = $licence->last_checked_at?->getTimestamp() ?? 0;
        if ($lastCheck > $now + 300) {
            return $this->result(Licence::READ_ONLY, 'The server clock moved backwards. Fix the time and re-check the licence.', $features);
        }

        $daysLeft = $exp > 0 ? (int) ceil(($exp - $now) / 86400) : null;

        if ($status === 'grace') {
            return $this->result(
                Licence::GRACE,
                'The vendor reports this licence is in its renewal grace period.'.$this->graceMessage($daysLeft),
                $features,
                $daysLeft,
            );
        }

        return $this->result(Licence::ACTIVE, 'Licence valid.', $features, $daysLeft);
    }

    public function allowsWrites(): bool
    {
        return in_array($this->evaluate()['state'], [Licence::ACTIVE, Licence::GRACE], true);
    }

    public function feature(string $name): bool
    {
        return (bool) ($this->evaluate()['features'][$name] ?? false);
    }

    /**
     * Entitlements are called `ent` in the vendor's token (`features` is
     * accepted too, for tokens issued before the schema was fixed). Reading the
     * wrong key here is silent and expensive: every gated module would simply
     * disappear while the licence looked healthy.
     */
    private function features(array $claim): array
    {
        $raw = $claim['ent'] ?? $claim['features'] ?? [];

        return array_map(fn ($v) => (bool) $v, (array) $raw);
    }

    private function graceMessage(?int $daysLeft): string
    {
        return $daysLeft !== null && $daysLeft > 0 ? ' About '.$daysLeft.' day(s) left.' : '';
    }

    /**
     * A stable identifier for this machine. The vendor binds a licence to it,
     * so it must survive a panel reinstall and must not be regenerated on every
     * request. /etc/machine-id is the Linux convention; hostname is the
     * fallback for containers that do not have one.
     */
    public function fingerprint(): string
    {
        $stored = Setting::get('server_fingerprint');

        if ($stored) {
            return (string) $stored;
        }

        $machine = is_readable('/etc/machine-id') ? (string) file_get_contents('/etc/machine-id') : '';
        $host = (string) (gethostname() ?: 'unknown');

        $fingerprint = hash('sha256', 'atlas-server|'.trim($machine).'|'.$host);

        Setting::put('server_fingerprint', $fingerprint);

        return $fingerprint;
    }

    /**
     * Activates a raw licence key against the central service and stores the
     * signed token it returns. This is the only path that obtains a *new*
     * licence; every later check is a heartbeat on the same key.
     *
     * @return array{ok: bool, message: string}
     */
    public function activate(string $key): array
    {
        try {
            $response = Http::timeout(20)->acceptJson()->post($this->endpoint('activate'), [
                'key' => $key,
                'fingerprint' => $this->fingerprint(),
                'hostname' => gethostname() ?: null,
                'os' => PHP_OS_FAMILY.' '.php_uname('r'),
                'version' => (string) config('atlas.version', '0.9.0'),
            ]);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => 'The central service is unreachable. Nothing changed: '.$e->getMessage(),
            ];
        }

        $token = (string) $response->json('token', '');

        if (! $response->successful() || $token === '') {
            $message = $response->json('error.message');

            return [
                'ok' => false,
                'message' => is_string($message) && $message !== ''
                    ? $message
                    : 'The vendor rejected this key (HTTP '.$response->status().').',
            ];
        }

        $claim = $this->verify($token);

        if (is_string($claim)) {
            return ['ok' => false, 'message' => 'The vendor sent a token this panel cannot verify: '.$claim];
        }

        $this->persist($token, $claim, $key);

        return ['ok' => true, 'message' => 'Licence activated. State: '.$this->evaluate()['state']];
    }

    /**
     * Asks the central service for a fresh token using the stored key.
     *
     * A network failure is not an error the operator can fix, so it is recorded
     * and the current state is kept — the panel keeps serving on the last
     * known-good token. Nothing about this path can enable a feature locally:
     * the vendor decides, and the answer is signed.
     */
    public function refresh(): bool
    {
        $licence = $this->current();
        $key = $licence->licence_key ?: (string) Setting::get('licence_key', '');

        if ($key === '') {
            return $this->fail('No licence key is installed on this server. Activate a key first.');
        }

        try {
            $response = Http::timeout(15)->acceptJson()->post($this->endpoint('heartbeat'), [
                'key' => $key,
                'fingerprint' => $this->fingerprint(),
                'version' => (string) config('atlas.version', '0.9.0'),
            ]);
        } catch (Throwable $e) {
            Log::info('licence refresh failed', ['error' => $e->getMessage()]);

            return $this->fail('The central service is unreachable. Running on the last known-good licence.');
        }

        $token = (string) $response->json('token', '');

        if ($response->status() === 404) {
            return $this->fail('The vendor does not recognise this key for this server.');
        }

        if (! $response->successful() || $token === '') {
            return $this->fail('The central service answered '.$response->status().'. Nothing changed.');
        }

        $claim = $this->verify($token);

        if (is_string($claim)) {
            return $this->fail('The vendor sent a token this panel cannot verify: '.$claim);
        }

        $this->persist($token, $claim);

        return true;
    }

    /**
     * Stores an already-verified token (the "paste a token" path). Kept public
     * so the WHM screen and the tests share one writer.
     */
    public function installVerifiedToken(string $token, array $claim): void
    {
        $this->persist($token, $claim);
    }

    /** The one place a token from the vendor is written to disk. */
    private function persist(string $token, array $claim, ?string $key = null): void
    {
        $licence = $this->current();

        $licence->forceFill([
            'token' => $token,
            'kid' => (string) ($claim['kid'] ?? ''),
            'features' => $this->features($claim),
            'expires_at' => isset($claim['exp']) ? now()->setTimestamp((int) $claim['exp']) : null,
            'fingerprint' => $this->fingerprint(),
            'last_checked_at' => now(),
            'last_error' => null,
        ]);

        if ($key !== null) {
            // Encrypted at rest by the model cast; it is a bearer credential
            // for the vendor API, not a secret the UI ever shows again.
            $licence->licence_key = $key;
        }

        $licence->save();

        Cache::forget('licence:evaluate');
    }

    private function fail(string $message): bool
    {
        $licence = $this->current();
        $licence->forceFill(['last_error' => $message, 'last_checked_at' => now()])->save();

        Cache::forget('licence:evaluate');

        return false;
    }

    private function endpoint(string $action): string
    {
        return rtrim((string) config('atlas.central.url'), '/').'/api/v1/license/'.$action;
    }

    /**
     * Verifies the signature and returns the claims, or a human-readable
     * reason string when the token is unusable.
     *
     * @return array<string, mixed>|string
     */
    public function verify(string $token)
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3 || $parts[0].'.' !== self::PREFIX) {
            return 'The licence token is malformed.';
        }

        [, $payload, $signature] = $parts;

        $json = $this->b64decode($payload);
        $sig = $this->b64decode($signature);

        if ($json === null || $sig === null) {
            return 'The licence token is malformed.';
        }

        $claim = json_decode($json, true);

        if (! is_array($claim)) {
            return 'The licence token payload is not readable.';
        }

        $kid = (string) ($claim['kid'] ?? '');
        $keys = (array) config('atlas.central.keys', []);
        $publicKey = $keys[$kid] ?? null;

        if (! $publicKey) {
            return 'The licence was signed with an unknown key ('.$kid.'). Update the panel to accept new keys.';
        }

        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            return 'The PHP sodium extension is required to verify licences.';
        }

        $rawKey = $this->b64decode($publicKey);

        if ($rawKey === null || ! sodium_crypto_sign_verify_detached($sig, self::PREFIX.$payload, $rawKey)) {
            return 'The licence signature is not valid for this server software.';
        }

        $nbf = (int) ($claim['nbf'] ?? 0);
        if ($nbf > 0 && time() < $nbf - 300) {
            return 'The licence is not valid yet — check the server clock.';
        }

        return $claim;
    }

    private function b64decode(string $value): ?string
    {
        $padded = strtr($value, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $raw = base64_decode($padded, true);

        return $raw === false ? null : $raw;
    }

    /**
     * @param  array<string, bool>  $features
     * @return array{state: string, reason: string, days_left: int|null, features: array<string, bool>}
     */
    private function result(string $state, string $reason, array $features = [], ?int $daysLeft = null): array
    {
        return compact('state', 'reason', 'features') + ['days_left' => $daysLeft];
    }
}
