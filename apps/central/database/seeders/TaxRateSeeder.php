<?php

namespace Database\Seeders;

use App\Models\TaxRate;
use Illuminate\Database\Seeder;

class TaxRateSeeder extends Seeder
{
    public function run(): void
    {
        // India: 18% GST on software licences (SAC 997331), the standard rate
        // for SaaS. A state-specific row overrides the national default.
        TaxRate::updateOrCreate(
            ['country' => 'IN', 'state' => null, 'kind' => 'gst'],
            ['rate_basis_points' => 1800, 'inclusive' => false, 'is_active' => true],
        );

        // Exports leave the country zero-rated under a LUT; no row needed, the
        // calculator short-circuits on a non-Indian billing country.
    }
}
