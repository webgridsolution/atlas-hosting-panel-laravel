<?php

namespace App\Domain\Licensing;

use App\Models\License;
use App\Models\LicenseKey;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Ed25519-signed licence tokens.
 *
 * The installed panel verifies these offline against a pinned public key, so it
 * can keep working when this server is unreachable. Rotating keys are addressed
 * by `kid`, and the payload carries the entitlement snapshot the panel enforces.
 */
final class TokenService
{
    private const PREFIX = 'atlas-lic-v1';

    public function activeKey(): LicenseKey
    {
        $key = LicenseKey::query()
            ->where('is_active', true)
            ->where('valid_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ->latest('id')
            ->first();

        return $key ?? $this->generateKey('lic-'.now()->format('Y-m').'-'.Str::lower(Str::random(4)));
    }

    public function generateKey(string $kid): LicenseKey
    {
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);

        return LicenseKey::create([
            'kid' => $kid,
            'public_key' => base64_encode($public),
            'private_key_enc' => Crypt::encryptString(base64_encode($secret)),
            'valid_from' => now()->subMinute(),
            'valid_until' => null,
            'is_active' => true,
        ]);
    }

    /** @return array{token:string, kid:string, claims:array} */
    public function issue(License $license, ?string $fingerprintHash = null, ?string $status = null): array
    {
        $key = $this->activeKey();
        $plan = $license->plan()->with('entitlements')->firstOrFail();

        $claims = [
            'v' => 1,
            'kid' => $key->kid,
            'lic' => $license->public_id,
            'cus' => (string) $license->customer_id,
            'ed' => $plan->edition,
            'fp' => $fingerprintHash ?? $license->fingerprint_hash,
            'ent' => $plan->entitlements->pluck('value', 'feature_key')->all(),
            'sts' => $status ?? $license->effectiveStatus()->value,
            'iat' => now()->getTimestamp(),
            'nbf' => now()->subMinutes(5)->getTimestamp(),
            'exp' => now()->addDays(30)->getTimestamp(),
            'jti' => (string) Str::ulid(),
        ];

        $payload = $this->b64(json_encode($claims));
        $signature = sodium_crypto_sign_detached(
            self::PREFIX.'.'.$payload,
            base64_decode($key->privateKey()),
        );

        return [
            'token' => self::PREFIX.'.'.$payload.'.'.$this->b64($signature),
            'kid' => $key->kid,
            'claims' => $claims,
        ];
    }

    /** @return array|null claims when the signature and time window are valid */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            return null;
        }

        $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'), true);

        if ($payloadJson === false) {
            return null;
        }

        $claims = json_decode($payloadJson, true);

        if (! is_array($claims) || empty($claims['kid'])) {
            return null;
        }

        $key = LicenseKey::where('kid', $claims['kid'])->first();

        if (! $key) {
            return null;
        }

        $valid = sodium_crypto_sign_verify_detached(
            base64_decode(strtr($parts[2], '-_', '+/')),
            self::PREFIX.'.'.$parts[1],
            base64_decode($key->public_key),
        );

        if (! $valid) {
            return null;
        }

        $now = now()->getTimestamp();

        if (($claims['nbf'] ?? 0) > $now + 300 || ($claims['exp'] ?? 0) < $now) {
            return null;
        }

        return $claims;
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
