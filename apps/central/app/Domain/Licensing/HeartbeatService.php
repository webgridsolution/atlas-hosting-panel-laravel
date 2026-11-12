<?php

namespace App\Domain\Licensing;

use App\Enums\LicenseStatus;
use App\Models\License;
use App\Models\LicenseEvent;
use App\Models\Server;

/**
 * Daily licence refresh. Never called from a page request on the panel.
 * A revoked/suspended licence returns a *signed* revoked token; the panel then
 * degrades to read-only — it never takes customer sites down.
 */
final class HeartbeatService
{
    public function __construct(
        private readonly LicenseKeyGenerator $keys,
        private readonly TokenService $tokens,
    ) {}

    public function handle(string $rawKey, string $fingerprint, ?string $panelVersion = null, ?string $ip = null): ActivationResult
    {
        $key = $this->keys->normalise($rawKey);
        $license = License::with('plan.entitlements')->where('key', $key)->first();

        if (! $license || ($license->fingerprint_hash !== null && $license->fingerprint_hash !== $fingerprint)) {
            return ActivationResult::failure(404, 'LICENSE_NOT_FOUND', 'Licence not recognised for this server.');
        }

        $status = $license->effectiveStatus();
        $license->update(['last_seen_at' => now()]);

        Server::where('license_id', $license->id)
            ->where('fingerprint_hash', $fingerprint)
            ->update(['status' => 'online', 'last_heartbeat_at' => now(), 'panel_version' => $panelVersion ?? null]);

        LicenseEvent::record($license, 'verified', [
            'actor_type' => 'panel',
            'ip' => $ip,
            'meta' => ['status' => $status->value, 'offline_safe' => true],
        ]);

        $issued = $this->tokens->issue($license, $fingerprint, $status->value);

        return ActivationResult::success([
            'status' => $status->value,
            'read_only' => in_array($status, [LicenseStatus::Expired, LicenseStatus::Suspended, LicenseStatus::Revoked], true),
            'expires_at' => $license->expires_at?->toIso8601String(),
            'grace_days' => $license->grace_days,
            'token' => $issued['token'],
            'kid' => $issued['kid'],
        ]);
    }

    public function deactivate(string $rawKey, string $fingerprint): ActivationResult
    {
        $license = License::where('key', $this->keys->normalise($rawKey))->first();

        if (! $license || $license->fingerprint_hash !== $fingerprint) {
            return ActivationResult::failure(404, 'LICENSE_NOT_FOUND', 'Licence not recognised for this server.');
        }

        $license->update(['fingerprint_hash' => null]);
        $license->activations()->where('fingerprint_hash', $fingerprint)->update([
            'status' => 'deactivated',
            'deactivated_at' => now(),
        ]);

        LicenseEvent::record($license, 'deactivated', ['actor_type' => 'panel', 'ip' => request()->ip()]);

        return ActivationResult::success(['status' => 'deactivated', 'message' => 'This server has been unbound. The licence can now activate elsewhere.']);
    }
}
