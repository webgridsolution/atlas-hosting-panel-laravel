<?php

namespace App\Domain\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Append-only double-entry ledger. Every posting group must balance to zero,
 * or the whole transaction aborts. Rows are never updated or deleted.
 */
final class LedgerService
{
    /**
     * @param  array<int, array{account:string, debit?:int, credit?:int}>  $postings
     */
    public function post(array $postings, string $currency, string $refType, string $refId, string $description): string
    {
        $debits = array_sum(array_column($postings, 'debit'));
        $credits = array_sum(array_column($postings, 'credit'));

        if ($debits !== $credits) {
            throw new \RuntimeException("Unbalanced ledger postings: debits {$debits} != credits {$credits}.");
        }

        $group = (string) Str::ulid();

        DB::table('financial_ledger')->insert(array_map(fn ($p) => [
            'entry_group' => $group,
            'account' => $p['account'],
            'debit_minor' => $p['debit'] ?? 0,
            'credit_minor' => $p['credit'] ?? 0,
            'currency' => $currency,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'description' => $description,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $postings));

        return $group;
    }

    public function sale(int $totalMinor, int $revenueMinor, int $cgst, int $sgst, int $igst, string $currency, string $refId): string
    {
        $postings = [
            ['account' => 'cash_razorpay', 'debit' => $totalMinor],
            ['account' => 'revenue_licences', 'credit' => $revenueMinor],
        ];

        if ($cgst > 0) {
            $postings[] = ['account' => 'gst_payable_cgst', 'credit' => $cgst];
        }
        if ($sgst > 0) {
            $postings[] = ['account' => 'gst_payable_sgst', 'credit' => $sgst];
        }
        if ($igst > 0) {
            $postings[] = ['account' => 'gst_payable_igst', 'credit' => $igst];
        }

        return $this->post($postings, $currency, 'payment', $refId, 'Licence sale');
    }

    public function refund(int $totalMinor, int $revenueMinor, int $cgst, int $sgst, int $igst, string $currency, string $refId): string
    {
        $postings = [
            ['account' => 'sales_returns', 'debit' => $revenueMinor],
            ['account' => 'cash_razorpay', 'credit' => $totalMinor],
        ];

        if ($cgst > 0) {
            $postings[] = ['account' => 'gst_payable_cgst', 'debit' => $cgst];
        }
        if ($sgst > 0) {
            $postings[] = ['account' => 'gst_payable_sgst', 'debit' => $sgst];
        }
        if ($igst > 0) {
            $postings[] = ['account' => 'gst_payable_igst', 'debit' => $igst];
        }

        return $this->post($postings, $currency, 'refund', $refId, 'Refund');
    }

    /** Signed balance across all accounts — must always be zero. */
    public function trialBalance(string $currency = 'INR'): int
    {
        $row = DB::table('financial_ledger')
            ->where('currency', $currency)
            ->selectRaw('COALESCE(SUM(debit_minor),0) as d, COALESCE(SUM(credit_minor),0) as c')
            ->first();

        return (int) ($row->d - $row->c);
    }
}
