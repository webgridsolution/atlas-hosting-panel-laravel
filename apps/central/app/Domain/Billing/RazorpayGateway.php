<?php

namespace App\Domain\Billing;

use Razorpay\Api\Api;

final class RazorpayGateway implements PaymentGateway
{
    private Api $api;

    public function __construct(
        private readonly string $keyId,
        private readonly string $keySecret,
    ) {
        $this->api = new Api($this->keyId, $this->keySecret);
    }

    public function name(): string
    {
        return 'razorpay';
    }

    public function publicKey(): string
    {
        return $this->keyId;
    }

    public function createOrder(string $receipt, int $amountMinor, string $currency, array $notes = []): array
    {
        $order = $this->api->order->create([
            'receipt' => $receipt,
            'amount' => $amountMinor,
            'currency' => $currency,
            'notes' => $notes,
        ]);

        return ['id' => $order['id'], 'amount' => (int) $order['amount'], 'currency' => $order['currency']];
    }

    public function verifyPaymentSignature(string $gatewayOrderId, string $gatewayPaymentId, string $signature): bool
    {
        // Razorpay signs "order_id|payment_id" with the key secret (HMAC-SHA256, hex).
        $expected = hash_hmac('sha256', $gatewayOrderId.'|'.$gatewayPaymentId, $this->keySecret);

        return hash_equals($expected, $signature);
    }

    public function fetchPayment(string $gatewayPaymentId): array
    {
        $payment = $this->api->payment->fetch($gatewayPaymentId);

        return [
            'id' => $payment['id'],
            'order_id' => $payment['order_id'] ?? null,
            'amount' => (int) $payment['amount'],
            'currency' => $payment['currency'] ?? 'INR',
            'status' => $payment['status'],
            'method' => $payment['method'] ?? null,
            'email' => $payment['email'] ?? null,
            'captured' => (bool) $payment['captured'],
        ];
    }

    public function refund(string $gatewayPaymentId, int $amountMinor, string $reason): array
    {
        $refund = $this->api->payment->fetch($gatewayPaymentId)->refund([
            'amount' => $amountMinor,
            'notes' => ['reason' => $reason],
        ]);

        return ['id' => $refund['id'], 'status' => $refund['status'], 'amount' => (int) $refund['amount']];
    }

    /** Webhook signature is HMAC-SHA256 over the RAW body, keyed with the webhook secret. */
    public function verifyWebhookSignature(string $rawBody, string $signature, string $webhookSecret): bool
    {
        return hash_equals(hash_hmac('sha256', $rawBody, $webhookSecret), $signature);
    }
}
