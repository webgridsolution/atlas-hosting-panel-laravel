<?php

namespace App\Http\Controllers;

use App\Models\License;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        // Staff have no customer profile; sending them to the control plane is
        // friendlier and more correct than a 403 on a page they bookmarked.
        if ($request->user()->isStaff()) {
            return redirect()->route('admin.dashboard');
        }

        $customer = $request->user()->customer;

        abort_if($customer === null, 403, 'No customer profile is attached to this account.');

        $licenses = $customer->licenses()->with('plan')->latest()->get();
        $servers = $customer->servers()->with('license.plan')->latest()->get();

        return view('dashboard', [
            'customer' => $customer,
            'licenses' => $licenses,
            'servers' => $servers,
            'primaryLicense' => $licenses->firstWhere(fn (License $l) => $l->effectiveStatus()->allowsFullOperation()) ?? $licenses->first(),
        ]);
    }
}
