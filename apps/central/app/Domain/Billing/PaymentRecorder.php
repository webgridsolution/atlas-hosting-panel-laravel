<?php

namespace App\Domain\Billing;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SecurityEvent;
use App\Models\Setting;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single place money becomes value. Idempotent on the provider payment id,
 * transactional, and it refuses to grant anything when the amount does not match
 * what we calculated.
 */
final class PaymentRecorder
{
    public function __construct(
        private readonly InvoiceNumberGenerator $numbers,
        private readonly LedgerService $ledger,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * @param  array{id:string, order_id:string|null, amount:int, currency:string, status:string, method:?string}  $provider
     */
    public function capture(Order $order, array $provider): Invoice
    {
        // Idempotent fast path: a replayed webhook or a double callback returns
        // the invoice it already produced.
        $existing = Payment::where('gateway_payment_id', $provider['id'])->first();
        if ($existing) {
            return Invoice::where('payment_id', $existing->id)->firstOrFail();
        }

        $order = Order::findOrFail($order->id);

        if ($order->status === 'paid') {
            return Invoice::where('order_id', $order->id)->firstOrFail();
        }

        $this->assertProviderMatchesOrder($order, $provider);

        return DB::transaction(function () use ($order, $provider) {
            $order = Order::lockForUpdate()->findOrFail($order->id);

            // Re-check under the lock: two workers can both pass the fast path.
            $existing = Payment::where('gateway_payment_id', $provider['id'])->first();
            if ($existing) {
                return Invoice::where('payment_id', $existing->id)->firstOrFail();
            }

            if ($order->status === 'paid') {
                return Invoice::where('order_id', $order->id)->firstOrFail();
            }

            $payment = Payment::create([
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'gateway' => 'razorpay',
                'gateway_payment_id' => $provider['id'],
                'gateway_order_id' => $provider['order_id'] ?? $order->gateway_order_id,
                'status' => 'captured',
                'currency' => $order->currency,
                'amount_minor' => (int) $provider['amount'],
                'method' => $provider['method'] ?? null,
                'signature_verified_at' => now(),
                'captured_at' => now(),
                'payload' => null,
            ]);

            $invoice = $this->createInvoice($order, $payment);

            $tax = $order->meta['tax'] ?? ['cgst' => 0, 'sgst' => 0, 'igst' => 0];

            $this->ledger->sale(
                totalMinor: $order->total_minor,
                revenueMinor: $order->subtotal_minor - $order->discount_minor,
                cgst: (int) $tax['cgst'],
                sgst: (int) $tax['sgst'],
                igst: (int) $tax['igst'],
                currency: $order->currency,
                refId: $payment->public_id,
            );

            $order->update(['status' => 'paid', 'paid_at' => now()]);

            // Fulfilment — a renewal extends the existing licence, anything else issues one.
            if (($order->meta['type'] ?? 'new') === 'renewal' && ! empty($order->meta['subscription_id'])) {
                $subscription = Subscription::findOrFail($order->meta['subscription_id']);
                $this->subscriptions->renew($subscription, now());
            } else {
                $this->subscriptions->startFromOrder($order, $payment);
            }

            AuditLog::record('payment.captured', $payment, [
                'order' => $order->number,
                'amount_minor' => $payment->amount_minor,
                'invoice' => $invoice->number,
            ]);

            return $invoice;
        });
    }

    /**
     * Amount and status checks happen *outside* the transaction on purpose:
     * a refusal must leave a permanent security event behind, which a rollback
     * would erase.
     */
    private function assertProviderMatchesOrder(Order $order, array $provider): void
    {
        if ((int) $provider['amount'] !== (int) $order->total_minor) {
            SecurityEvent::raise('payment_amount_mismatch', 'critical', [
                'order' => $order->number,
                'expected' => $order->total_minor,
                'received' => (int) $provider['amount'],
                'payment' => $provider['id'],
            ]);

            throw new RuntimeException("Amount mismatch for order {$order->number}: expected {$order->total_minor}, got {$provider['amount']}.");
        }

        if (($provider['status'] ?? '') !== 'captured') {
            throw new RuntimeException("Payment {$provider['id']} is not captured ({$provider['status']}).");
        }
    }

    private function createInvoice(Order $order, Payment $payment): Invoice
    {
        $issuedAt = now();
        $fy = $this->numbers->financialYear($issuedAt);
        $tax = $order->meta['tax'] ?? ['cgst' => 0, 'sgst' => 0, 'igst' => 0, 'rate_basis_points' => 0, 'is_export' => false, 'place_of_supply' => 'IN'];
        $customer = $order->customer;
        $taxable = $order->subtotal_minor - $order->discount_minor;

        $invoice = Invoice::create([
            'number' => $this->numbers->next($fy),
            'financial_year' => $fy,
            'customer_id' => $order->customer_id,
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'status' => 'paid',
            'currency' => $order->currency,
            'subtotal_minor' => $order->subtotal_minor,
            'discount_minor' => $order->discount_minor,
            'tax_minor' => $order->tax_minor,
            'total_minor' => $order->total_minor,
            'place_of_supply' => $tax['place_of_supply'] ?? 'IN',
            'is_export' => (bool) ($tax['is_export'] ?? false),
            'buyer_name' => $customer->company_name ?: $customer->user->name,
            'buyer_email' => $customer->user->email,
            'buyer_gstin' => $customer->gstin,
            'seller_snapshot' => [
                'name' => Setting::get('company.name', config('app.name')),
                'state' => Setting::get('billing.seller_state', config('billing.seller_state', 'JH')),
                'gstin' => Setting::get('company.gstin', config('billing.seller_gstin')),
                'address' => Setting::get('company.address', config('billing.seller_address')),
            ],
            'issued_at' => $issuedAt,
        ]);

        foreach ($order->items as $item) {
            $invoice->items()->create([
                'description' => $item->description,
                'sac_code' => '997331',
                'quantity' => $item->quantity,
                'unit_price_minor' => $item->unit_price_minor,
                'line_total_minor' => $item->line_total_minor,
                'taxable_minor' => $taxable,
                'cgst_minor' => (int) ($tax['cgst'] ?? 0),
                'sgst_minor' => (int) ($tax['sgst'] ?? 0),
                'igst_minor' => (int) ($tax['igst'] ?? 0),
                'rate_basis_points' => (int) ($tax['rate_basis_points'] ?? 0),
            ]);
        }

        return $invoice;
    }
}
