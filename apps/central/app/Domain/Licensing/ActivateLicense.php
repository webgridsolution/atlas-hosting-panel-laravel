<?php

namespace App\Domain\Licensing;

use App\Enums\LicenseStatus;
use App\Models\ActivationNonce;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\LicenseEvent;
use App\Models\SecurityEvent;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Binds a licence key to one server fingerprint.
 *
 * Failure modes are uniform and deliberately vague to the caller so the endpoint
 * cannot be used as an oracle for which keys exist.
 */
final class ActivateLicense
{
    public function __construct(
        private readonly LicenseKeyGenerator $keys,
        private readonly TokenService $tokens,
    ) {}

    public function handle(
        string $rawKey,
        string $fingerprint,
        ?string $hostname = null,
        ?string $os = null,
        ?string $panelVersion = null,
        ?string $ip = null,
        ?string $nonce = null,
    ): ActivationResult {
        if (! $this->consumeNonce($nonce, $fingerprint)) {
            SecurityEvent::raise('activation_replay', 'high', ['fingerprint' => substr($fingerprint, 0, 16)]);

            return ActivationResult::failure(409, 'REPLAY_DETECTED', 'This activation request was already used.');
        }

        $key = $this->keys->normalise($rawKey);
        $license = License::with('plan.entitlements')->where('key', $key)->first();

        if (! $license) {
            // Same shape and status whether the key is unknown or malformed.
            return ActivationResult::failure(404, 'LICENSE_NOT_FOUND', 'Licence key not recognised.');
        }

        $status = $license->effectiveStatus();

        if (in_array($status, [LicenseStatus::Revoked, LicenseStatus::Suspended], true)) {
            SecurityEvent::raise('activation_blocked_'.$status->value, 'high', [
                'license' => $license->public_id,
                'ip' => $ip,
            ]);

            $code = $status === LicenseStatus::Revoked ? 'LICENSE_REVOKED' : 'LICENSE_SUSPENDED';

            return ActivationResult::failure(403, $code, "This licence is {$status->label()}.");
        }

        if ($status === LicenseStatus::Expired) {
            return ActivationResult::failure(410, 'LICENSE_EXPIRED', 'This licence has expired. Renew to activate new servers.');
        }

        return DB::transaction(function () use ($license, $fingerprint, $hostname, $os, $panelVersion, $ip) {
            $license = License::lockForUpdate()->findOrFail($license->id);
            $bound = $license->fingerprint_hash;

            if ($bound !== null && $bound !== $fingerprint) {
                // Different machine: only allowed if the edition permits more activations.
                $active = LicenseActivation::where('license_id', $license->id)->where('status', 'active')->count();

                if ($active >= $license->activation_limit) {
                    SecurityEvent::raise('activation_limit_reached', 'medium', [
                        'license' => $license->public_id,
                        'ip' => $ip,
                    ]);

                    return ActivationResult::failure(409, 'ACTIVATION_LIMIT_REACHED', 'This licence is already active on another server.');
                }
            }

            $license->update([
                'status' => $license->status === LicenseStatus::PendingActivation ? LicenseStatus::Active : $license->status,
                'fingerprint_hash' => $bound ?? $fingerprint,
                'activated_at' => $license->activated_at ?? now(),
                'last_seen_at' => now(),
            ]);

            $server = $this->upsertServer($license, $fingerprint, $hostname, $os, $panelVersion, $ip);

            LicenseActivation::updateOrCreate(
                ['license_id' => $license->id, 'fingerprint_hash' => $fingerprint, 'status' => 'active'],
                [
                    'server_id' => $server->id,
                    'ip_address' => $ip,
                    'panel_version' => $panelVersion,
                    'activated_at' => now(),
                ],
            );

            LicenseEvent::record($license, 'activated', [
                'actor_type' => 'panel',
                'ip' => $ip,
                'meta' => ['fingerprint' => substr($fingerprint, 0, 16), 'hostname' => $hostname, 'os' => $os],
            ]);

            $issued = $this->tokens->issue($license->fresh(), $fingerprint);

            return ActivationResult::success([
                'license' => [
                    'public_id' => $license->public_id,
                    'edition' => $license->plan->edition,
                    'status' => $license->fresh()->effectiveStatus()->value,
                    'issued_at' => $license->created_at->toIso8601String(),
                    'expires_at' => $license->expires_at?->toIso8601String(),
                    'grace_days' => $license->grace_days,
                    'activation_limit' => $license->activation_limit,
                    'entitlements' => $license->plan->entitlements->pluck('value', 'feature_key')->all(),
                ],
                'token' => $issued['token'],
                'kid' => $issued['kid'],
                'server' => [
                    'public_id' => $server->public_id,
                    'hostname' => $server->hostname,
                    'status' => $server->status,
                ],
            ]);
        });
    }

    private function upsertServer(License $license, string $fingerprint, ?string $hostname, ?string $os, ?string $panelVersion, ?string $ip): Server
    {
        $server = Server::firstOrNew(['fingerprint_hash' => $fingerprint]);

        $server->fill([
            'customer_id' => $license->customer_id,
            'license_id' => $license->id,
            'hostname' => $hostname ?: ($server->hostname ?: 'unknown-'.Str::lower(Str::random(6))),
            'os' => $os ?: $server->os,
            'panel_version' => $panelVersion ?: $server->panel_version,
            'ip_address' => $ip ?: $server->ip_address,
            'status' => 'online',
            'last_heartbeat_at' => now(),
        ])->save();

        return $server;
    }

    /** One-shot nonce; absent nonces are allowed for older clients but logged. */
    private function consumeNonce(?string $nonce, string $fingerprint): bool
    {
        if (! $nonce) {
            return true;
        }

        ActivationNonce::where('expires_at', '<', now())->delete();

        if (ActivationNonce::where('nonce', hash('sha256', $nonce))->exists()) {
            return false;
        }

        ActivationNonce::create([
            'nonce' => hash('sha256', $nonce),
            'fingerprint_hash' => $fingerprint,
            'expires_at' => now()->addMinutes(15),
        ]);

        return true;
    }
}
