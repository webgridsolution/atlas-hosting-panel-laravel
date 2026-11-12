<?php

namespace App\Domain\Billing;

use App\Models\Invoice;
use Illuminate\Support\Carbon;

/**
 * Sequential invoice numbers per Indian financial year: INV/2026-27/0001.
 * Gaps are not permitted, so the number is allocated inside the invoice transaction.
 */
final class InvoiceNumberGenerator
{
    public function financialYear(Carbon $date): string
    {
        $startYear = $date->month >= 4 ? $date->year : $date->year - 1;

        return $startYear.'-'.substr((string) ($startYear + 1), -2);
    }

    public function next(string $financialYear): string
    {
        $count = Invoice::query()
            ->where('financial_year', $financialYear)
            ->lockForUpdate()
            ->count();

        return sprintf('INV/%s/%04d', $financialYear, $count + 1);
    }
}
