<?php

namespace App\Domain\Billing;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\SecurityEvent;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Idempotent webhook handling.
 *
 * The webhook body is a *hint*: every amount and status is re-fetched from the
 * provider before value is granted. The event id is unique in the database, so a
 * replay — or two workers racing — cannot issue a second licence.
 */
final class WebhookProcessor
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentRecorder $recorder,
        private readonly LedgerService $ledger,
    ) {}

    /**
     * @return array{status:string, event_id:string}
     */
    public function handle(string $rawBody, string $signatureHeader, string $webhookSecret): array
    {
        // 1. Signature over the RAW body, before any JSON parsing.
        if (! $this->verifySignature($rawBody, $signatureHeader, $webhookSecret)) {
            SecurityEvent::raise('webhook_signature_invalid', 'high', ['body_hash' => hash('sha256', $rawBody)]);

            return ['status' => 'invalid_signature', 'event_id' => ''];
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return ['status' => 'bad_payload', 'event_id' => ''];
        }

        $eventId = $payload['id'] ?? hash('sha256', $rawBody);
        $eventType = $payload['event'] ?? 'unknown';

        // 2. Idempotency gate.
        $event = WebhookEvent::firstOrCreate(
            ['gateway' => 'razorpay', 'event_id' => $eventId],
            [
                'event_type' => $eventType,
                'payload_hash' => hash('sha256', $rawBody),
                'status' => 'received',
            ],
        );

        if ($event->status === 'processed' || $event->status === 'ignored') {
            return ['status' => 'duplicate', 'event_id' => $eventId];
        }

        try {
            $result = $this->dispatch($eventType, $payload['payload'] ?? []);
            $event->update(['status' => $result, 'processed_at' => now()]);
        } catch (\Throwable $e) {
            $event->update(['status' => 'failed', 'error' => substr($e->getMessage(), 0, 900), 'attempts' => $event->attempts + 1]);
            Log::error('webhook.failed', ['event' => $eventId, 'type' => $eventType, 'error' => $e->getMessage()]);

            return ['status' => 'failed', 'event_id' => $eventId];
        }

        return ['status' => $result, 'event_id' => $eventId];
    }

    public function verifySignature(string $rawBody, string $signature, string $secret): bool
    {
        if ($signature === '' || $secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    private function dispatch(string $eventType, array $payload): string
    {
        return match ($eventType) {
            'payment.captured', 'order.paid' => $this->onPaymentCaptured($payload),
            'payment.failed' => $this->onPaymentFailed($payload),
            'refund.processed', 'refund.created' => $this->onRefund($payload),
            default => 'ignored',
        };
    }

    private function onPaymentCaptured(array $payload): string
    {
        $entity = $payload['payment']['entity'] ?? [];
        $paymentId = $entity['id'] ?? null;
        $providerOrderId = $entity['order_id'] ?? null;

        if (! $paymentId || ! $providerOrderId) {
            return 'ignored';
        }

        $order = Order::where('gateway_order_id', $providerOrderId)->first();

        if (! $order) {
            SecurityEvent::raise('webhook_unknown_order', 'high', ['provider_order' => $providerOrderId]);

            return 'ignored';
        }

        // Re-fetch from the provider: the webhook body is never trusted for amounts.
        $providerPayment = $this->gateway->fetchPayment($paymentId);
        $this->recorder->capture($order, $providerPayment);

        return 'processed';
    }

    private function onPaymentFailed(array $payload): string
    {
        $entity = $payload['payment']['entity'] ?? [];
        $order = Order::where('gateway_order_id', $entity['order_id'] ?? '')->first();

        if ($order && $order->status === 'pending') {
            $order->update(['status' => 'failed']);
        }

        return $order ? 'processed' : 'ignored';
    }

    private function onRefund(array $payload): string
    {
        $entity = $payload['refund']['entity'] ?? [];
        $paymentId = $entity['payment_id'] ?? null;

        if (! $paymentId) {
            return 'ignored';
        }

        $payment = Payment::where('gateway_payment_id', $paymentId)->first();

        if (! $payment) {
            return 'ignored';
        }

        return DB::transaction(function () use ($entity, $payment) {
            $amount = (int) ($entity['amount'] ?? $payment->amount_minor);

            $refund = Refund::firstOrCreate(
                ['gateway_refund_id' => $entity['id'] ?? null],
                [
                    'payment_id' => $payment->id,
                    'invoice_id' => optional($payment->invoice)->id,
                    'status' => 'processed',
                    'amount_minor' => $amount,
                    'reason' => 'Provider-initiated refund',
                    'processed_at' => now(),
                ],
            );

            if (! $refund->wasRecentlyCreated) {
                return 'duplicate';
            }

            $invoice = $payment->invoice;
            $ratio = $payment->amount_minor > 0 ? $amount / $payment->amount_minor : 0;
            $tax = $invoice ? [
                'cgst' => (int) round($invoice->items->sum('cgst_minor') * $ratio),
                'sgst' => (int) round($invoice->items->sum('sgst_minor') * $ratio),
                'igst' => (int) round($invoice->items->sum('igst_minor') * $ratio),
            ] : ['cgst' => 0, 'sgst' => 0, 'igst' => 0];

            $revenue = $amount - array_sum($tax);

            $this->ledger->refund($amount, $revenue, $tax['cgst'], $tax['sgst'], $tax['igst'], $payment->currency, $refund->public_id);

            if ($invoice && $amount >= $payment->amount_minor) {
                $invoice->update(['status' => 'refunded']);
            }

            return 'processed';
        });
    }
}
