<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Seller identity (printed on every invoice)
    |--------------------------------------------------------------------------
    */
    'seller_state' => env('BILLING_SELLER_STATE', 'JH'),
    'seller_gstin' => env('BILLING_SELLER_GSTIN', ''),
    'seller_address' => env('BILLING_SELLER_ADDRESS', ''),

    /*
    |--------------------------------------------------------------------------
    | Tax
    |--------------------------------------------------------------------------
    | Rates live in the `tax_rates` table; this is only the fallback.
    */
    'default_gst_basis_points' => 1800,

    /*
    |--------------------------------------------------------------------------
    | Multi-period discounts (basis points) applied to monthly-equivalent price
    |--------------------------------------------------------------------------
    */
    'interval_discount_bp' => [
        'monthly' => 0,
        'quarterly' => 500,
        'half_yearly' => 1000,
        'annual' => 1500,
    ],
];
