<?php

namespace App\Domain\Billing;

use App\Models\TaxRate;

/**
 * Indian GST for software licences (SAC 997331).
 *
 * - Buyer in India, same state as seller  → CGST + SGST (each half the rate)
 * - Buyer in India, different state       → IGST (full rate)
 * - Buyer outside India                   → export, zero-rated under LUT
 *
 * The rate is read from `tax_rates`; nothing here is hardcoded to 18%.
 */
final class GstCalculator
{
    public function __construct(private readonly string $sellerState = 'JH') {}

    /**
     * @return array{
     *   is_export:bool, rate_basis_points:int,
     *   cgst:int, sgst:int, igst:int, total_tax:int, place_of_supply:string
     * }
     */
    public function calculate(int $taxableMinor, string $buyerCountry, ?string $buyerState): array
    {
        $buyerCountry = strtoupper($buyerCountry ?: 'IN');
        $placeOfSupply = $buyerCountry === 'IN' ? strtoupper($buyerState ?: $this->sellerState) : $buyerCountry;

        if ($buyerCountry !== 'IN') {
            return [
                'is_export' => true,
                'rate_basis_points' => 0,
                'cgst' => 0, 'sgst' => 0, 'igst' => 0,
                'total_tax' => 0,
                'place_of_supply' => $placeOfSupply,
            ];
        }

        $rate = TaxRate::query()
            ->where('country', 'IN')
            ->where('kind', 'gst')
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('state')->orWhere('state', $placeOfSupply))
            ->orderByRaw('state is null') // prefer a state-specific row
            ->first();

        $bp = $rate?->rate_basis_points ?? 1800;
        $totalTax = (int) round($taxableMinor * $bp / 10000);

        $intraState = $placeOfSupply === $this->sellerState;

        $cgst = $intraState ? intdiv($totalTax, 2) : 0;
        $sgst = $intraState ? $totalTax - $cgst : 0;
        $igst = $intraState ? 0 : $totalTax;

        return [
            'is_export' => false,
            'rate_basis_points' => $bp,
            'cgst' => $cgst,
            'sgst' => $sgst,
            'igst' => $igst,
            'total_tax' => $totalTax,
            'place_of_supply' => $placeOfSupply,
        ];
    }
}
