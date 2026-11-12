<?php

namespace App\Domain\Billing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Deterministic payment gateway for development and demos.
 *
 * It is only ever bound when ATLAS_PAYMENTS_DRIVER=demo, and the route that uses
 * it refuses to run with the razorpay driver. Amounts always come from the order;
 * the staged "capture" records exactly what the order says, so the invoicing,
 * ledger and licensing paths are exercised for real.
 */
final class DemoPaymentGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'demo';
    }

    public function publicKey(): string
    {
        return 'demo_public_key';
    }

    public function createOrder(string $receipt, int $amountMinor, string $currency, array $notes = []): array
    {
        $id = 'order_DEMO'.Str::upper(Str::random(10));

        Cache::put("demo.order.{$id}", compact('receipt', 'amountMinor', 'currency'), now()->addHour());

        return ['id' => $id, 'amount' => $amountMinor, 'currency' => $currency];
    }

    public function verifyPaymentSignature(string $gatewayOrderId, string $gatewayPaymentId, string $signature): bool
    {
        return $signature === 'demo-signature';
    }

    /** Test/demo helper: pretend the provider captured the order amount. */
    public function simulateCapture(string $gatewayOrderId): array
    {
        $order = Cache::get("demo.order.{$gatewayOrderId}");

        if (! $order) {
            throw new \RuntimeException('Unknown demo order.');
        }

        $payment = [
            'id' => 'pay_DEMO'.Str::upper(Str::random(10)),
            'order_id' => $gatewayOrderId,
            'amount' => $order['amountMinor'],
            'currency' => $order['currency'],
            'status' => 'captured',
            'method' => 'demo',
        ];

        Cache::put("demo.payment.{$payment['id']}", $payment, now()->addHour());

        return $payment;
    }

    public function fetchPayment(string $gatewayPaymentId): array
    {
        $payment = Cache::get("demo.payment.{$gatewayPaymentId}");

        if (! $payment) {
            throw new \RuntimeException("Unknown demo payment {$gatewayPaymentId}");
        }

        return $payment;
    }

    public function refund(string $gatewayPaymentId, int $amountMinor, string $reason): array
    {
        return [
            'id' => 'rfnd_DEMO'.Str::upper(Str::random(10)),
            'status' => 'processed',
            'amount' => $amountMinor,
        ];
    }
}
