<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Billing\LedgerService;
use App\Domain\Billing\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BillingAdminController extends Controller
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly LedgerService $ledger,
    ) {}

    public function orders(Request $request): View
    {
        $this->authorize('viewAny', Order::class);

        return view('admin.billing.orders', [
            'orders' => Order::with(['customer.user', 'payment'])
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->latest()->paginate(20)->withQueryString(),
            'status' => $request->query('status'),
        ]);
    }

    public function invoices(Request $request): View
    {
        $this->authorize('viewAny', Invoice::class);

        return view('admin.billing.invoices', [
            'invoices' => Invoice::with(['customer.user', 'payment'])->latest('issued_at')->paginate(20),
            'trialBalanceMinor' => $this->ledger->trialBalance('INR'),
            'revenueMinor' => (int) DB::table('financial_ledger')->where('account', 'revenue_licences')->sum('credit_minor')
                - (int) DB::table('financial_ledger')->where('account', 'revenue_licences')->sum('debit_minor'),
        ]);
    }

    public function invoice(Invoice $invoice): View
    {
        $this->authorize('viewAny', Invoice::class);

        return view('admin.billing.invoice', [
            'invoice' => $invoice->load('items', 'payment', 'customer.user'),
            'refunds' => Refund::where('payment_id', $invoice->payment_id)->latest()->get(),
        ]);
    }

    public function subscriptions(): View
    {
        $this->authorize('viewAny', Subscription::class);

        return view('admin.billing.subscriptions', [
            'subscriptions' => Subscription::with(['customer.user', 'plan', 'license'])->latest()->paginate(20),
        ]);
    }

    public function storeRefund(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('create', Refund::class);

        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1', 'max:'.$payment->amount_minor],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $provider = $this->gateway->refund($payment->gateway_payment_id, (int) $data['amount_minor'], $data['reason']);

        $refund = Refund::create([
            'payment_id' => $payment->id,
            'invoice_id' => $payment->invoice?->id,
            'gateway_refund_id' => $provider['id'],
            'status' => 'processed',
            'amount_minor' => (int) $data['amount_minor'],
            'reason' => $data['reason'],
            'requested_by' => $request->user()->id,
            'processed_at' => now(),
        ]);

        $invoice = $payment->invoice;
        $ratio = $data['amount_minor'] / $payment->amount_minor;
        $tax = [
            'cgst' => (int) round(($invoice?->items->sum('cgst_minor') ?? 0) * $ratio),
            'sgst' => (int) round(($invoice?->items->sum('sgst_minor') ?? 0) * $ratio),
            'igst' => (int) round(($invoice?->items->sum('igst_minor') ?? 0) * $ratio),
        ];

        $this->ledger->refund(
            (int) $data['amount_minor'],
            (int) $data['amount_minor'] - array_sum($tax),
            $tax['cgst'], $tax['sgst'], $tax['igst'],
            $payment->currency,
            $refund->public_id,
        );

        if ($invoice && (int) $data['amount_minor'] >= $payment->amount_minor) {
            $invoice->update(['status' => 'refunded']);
        }

        AuditLog::record('refund.issued', $refund, ['amount_minor' => $refund->amount_minor, 'reason' => $data['reason']]);

        return back()->with('status', "Refund of {$refund->amount_minor} minor units recorded against {$payment->gateway_payment_id}.");
    }
}
