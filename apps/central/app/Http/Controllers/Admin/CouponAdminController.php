<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Coupon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CouponAdminController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Coupon::class);

        return view('admin.coupons.index', ['coupons' => Coupon::latest()->paginate(20)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Coupon::class);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'alpha_num', 'unique:coupons,code'],
            'kind' => ['required', 'in:percent,fixed'],
            'value' => ['required', 'integer', 'min:1'],
            'currency' => ['nullable', 'in:INR,USD'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'ends_at' => ['nullable', 'date', 'after:today'],
        ]);

        $coupon = Coupon::create([
            'code' => strtoupper($data['code']),
            'kind' => $data['kind'],
            // percent is entered as a percentage (10 = 10%) and stored in basis points;
            // fixed is entered in major units and stored in minor units.
            'value' => $data['kind'] === 'fixed' ? $data['value'] * 100 : $data['value'] * 100,
            'currency' => $data['kind'] === 'fixed' ? ($data['currency'] ?? 'INR') : null,
            'max_redemptions' => $data['max_redemptions'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'is_active' => true,
        ]);

        AuditLog::record('coupon.created', $coupon, ['code' => $coupon->code]);

        return back()->with('status', "Coupon {$coupon->code} created.");
    }

    public function toggle(Coupon $coupon): RedirectResponse
    {
        $this->authorize('update', $coupon);

        $coupon->update(['is_active' => ! $coupon->is_active]);
        AuditLog::record('coupon.toggled', $coupon, ['is_active' => $coupon->is_active]);

        return back()->with('status', "Coupon {$coupon->code} is now ".($coupon->is_active ? 'active' : 'disabled').'.');
    }
}
