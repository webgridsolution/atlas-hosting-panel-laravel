<?php

namespace Tests\Support;

use App\Domain\Billing\PaymentGateway;

/**
 * Deterministic gateway for tests. Records every call so tests can assert on
 * what the application asked the provider to do — and never touches the network.
 */
class FakePaymentGateway implements PaymentGateway
{
    public array $createdOrders = [];

    public array $refunds = [];

    /** @var array<string, array> */
    public array $payments = [];

    public int $nextId = 1;

    public function name(): string
    {
        return 'razorpay';
    }

    public function publicKey(): string
    {
        return 'rzp_test_FAKE';
    }

    public function createOrder(string $receipt, int $amountMinor, string $currency, array $notes = []): array
    {
        $id = 'order_FAKE'.($this->nextId++);
        $this->createdOrders[] = compact('id', 'receipt', 'amountMinor', 'currency', 'notes');

        return ['id' => $id, 'amount' => $amountMinor, 'currency' => $currency];
    }

    /** Test helper: stage a paid payment as the provider would report it. */
    public function stageCapturedPayment(string $paymentId, string $orderId, int $amountMinor, array $overrides = []): void
    {
        $this->payments[$paymentId] = array_merge([
            'id' => $paymentId,
            'order_id' => $orderId,
            'amount' => $amountMinor,
            'currency' => 'INR',
            'status' => 'captured',
            'method' => 'upi',
            'email' => 'buyer@example.com',
            'captured' => true,
        ], $overrides);
    }

    public function verifyPaymentSignature(string $gatewayOrderId, string $gatewayPaymentId, string $signature): bool
    {
        return $signature === 'valid-signature';
    }

    public function fetchPayment(string $gatewayPaymentId): array
    {
        if (! isset($this->payments[$gatewayPaymentId])) {
            throw new \RuntimeException("Unknown payment {$gatewayPaymentId}");
        }

        return $this->payments[$gatewayPaymentId];
    }

    public function refund(string $gatewayPaymentId, int $amountMinor, string $reason): array
    {
        $id = 'rfnd_FAKE'.($this->nextId++);
        $this->refunds[] = compact('id', 'gatewayPaymentId', 'amountMinor', 'reason');

        return ['id' => $id, 'status' => 'processed', 'amount' => $amountMinor];
    }
}
