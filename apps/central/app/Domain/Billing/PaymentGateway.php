<?php

namespace App\Domain\Billing;

interface PaymentGateway
{
    /** Name stored on payment rows. */
    public function name(): string;

    /** Public key handed to the browser checkout. Never the secret. */
    public function publicKey(): string;

    /** @return array{id:string, amount:int, currency:string} */
    public function createOrder(string $receipt, int $amountMinor, string $currency, array $notes = []): array;

    /** Verifies the browser callback signature: HMAC(order_id|payment_id, secret). */
    public function verifyPaymentSignature(string $gatewayOrderId, string $gatewayPaymentId, string $signature): bool;

    /** Authoritative fetch — never trust the browser or the webhook body for amounts. */
    public function fetchPayment(string $gatewayPaymentId): array;

    /** @return array{id:string, status:string, amount:int} */
    public function refund(string $gatewayPaymentId, int $amountMinor, string $reason): array;
}
