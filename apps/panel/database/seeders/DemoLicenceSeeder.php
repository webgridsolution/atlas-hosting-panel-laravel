<?php

namespace Database\Seeders;

use App\Models\Licence;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Mints a short-lived licence signed with a locally generated key, and teaches
 * the panel to trust that key through storage/app/licence/keys.json.
 *
 * This exists so a demo install shows the real verification path — signature,
 * key id, expiry, grace — rather than a hard-coded "active" badge. On a real
 * server the key comes from the vendor and this seeder is never run.
 */
class DemoLicenceSeeder extends Seeder
{
    public function run(): void
    {
        $dir = storage_path('app/licence');

        if (! is_dir($dir)) {
            mkdir($dir, 0o750, true);
        }

        $privatePath = $dir.'/demo-private.key';
        $keysPath = $dir.'/keys.json';

        if (! is_file($privatePath)) {
            $pair = sodium_crypto_sign_keypair();
            file_put_contents($privatePath, base64_encode(sodium_crypto_sign_secretkey($pair)));
            file_put_contents($keysPath, json_encode([
                'demo-2026' => $this->b64url(sodium_crypto_sign_publickey($pair)),
            ], JSON_PRETTY_PRINT));
            chmod($privatePath, 0o600);
        }

        $keys = json_decode((string) file_get_contents($keysPath), true) ?: [];
        config(['atlas.central.keys' => $keys + (array) config('atlas.central.keys')]);

        if (Licence::whereNotNull('token')->exists()) {
            return;
        }

        $secret = base64_decode((string) file_get_contents($privatePath), true);

        if ($secret === false) {
            return;
        }

        $payload = [
            'kid' => 'demo-2026',
            'server' => Setting::get('server_id', 'SRV-DEMO-0001'),
            'plan' => 'agency',
            'features' => ['mail' => true, 'dns' => true, 'backups' => true],
            'iat' => time(),
            'nbf' => time() - 60,
            'exp' => time() + 90 * 86400,
        ];

        $body = $this->b64url(json_encode($payload));
        $signature = sodium_crypto_sign_detached('atlas-lic-v1.'.$body, $secret);

        Licence::create([
            'token' => 'atlas-lic-v1.'.$body.'.'.$this->b64url($signature),
            'kid' => 'demo-2026',
            'state' => 'active',
            'features' => $payload['features'],
            'expires_at' => now()->addDays(90),
            'last_checked_at' => now(),
        ]);
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
