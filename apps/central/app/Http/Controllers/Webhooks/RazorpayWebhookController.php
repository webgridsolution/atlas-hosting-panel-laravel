<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Billing\WebhookProcessor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RazorpayWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookProcessor $processor): JsonResponse
    {
        // Raw body only — Laravel must not have parsed this request.
        $raw = $request->getContent();
        $signature = (string) $request->header('X-Razorpay-Signature', '');
        $secret = (string) config('services.razorpay.webhook_secret');

        $result = $processor->handle($raw, $signature, $secret);

        return match ($result['status']) {
            'invalid_signature' => response()->json(['error' => 'invalid signature'], 400),
            'failed' => response()->json(['status' => 'failed', 'event' => $result['event_id']], 500),
            default => response()->json(['status' => $result['status'], 'event' => $result['event_id']]),
        };
    }
}
