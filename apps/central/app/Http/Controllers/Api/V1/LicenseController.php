<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Licensing\ActivateLicense;
use App\Domain\Licensing\ActivationResult;
use App\Domain\Licensing\HeartbeatService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LicenseController extends Controller
{
    public function __construct(
        private readonly ActivateLicense $activate,
        private readonly HeartbeatService $heartbeat,
    ) {}

    public function activate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:40'],
            'fingerprint' => ['required', 'string', 'min:16', 'max:128'],
            'hostname' => ['nullable', 'string', 'max:253'],
            'os' => ['nullable', 'string', 'max:40'],
            'version' => ['nullable', 'string', 'max:20'],
            'nonce' => ['nullable', 'string', 'max:96'],
        ]);

        $result = $this->activate->handle(
            rawKey: $data['key'],
            fingerprint: $data['fingerprint'],
            hostname: $data['hostname'] ?? null,
            os: $data['os'] ?? null,
            panelVersion: $data['version'] ?? null,
            ip: $request->ip(),
            nonce: $data['nonce'] ?? null,
        );

        return $this->respond($result);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:40'],
            'fingerprint' => ['required', 'string', 'min:16', 'max:128'],
            'version' => ['nullable', 'string', 'max:20'],
        ]);

        return $this->respond($this->heartbeat->handle(
            rawKey: $data['key'],
            fingerprint: $data['fingerprint'],
            panelVersion: $data['version'] ?? null,
            ip: $request->ip(),
        ));
    }

    public function deactivate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:40'],
            'fingerprint' => ['required', 'string', 'min:16', 'max:128'],
        ]);

        return $this->respond($this->heartbeat->deactivate($data['key'], $data['fingerprint']));
    }

    private function respond(ActivationResult $result): JsonResponse
    {
        return response()->json(
            $result->ok
                ? $result->body
                : ['error' => ['code' => $result->code, 'message' => $result->message]],
            $result->httpStatus,
        );
    }
}
