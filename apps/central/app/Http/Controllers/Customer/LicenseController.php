<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\License;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LicenseController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', License::class);

        $licenses = $request->user()->customer->licenses()
            ->with('plan', 'servers')
            ->latest()
            ->paginate(15);

        return view('customer.licenses.index', ['licenses' => $licenses]);
    }

    public function show(Request $request, License $license): View
    {
        $this->authorize('view', $license);

        return view('customer.licenses.show', [
            'license' => $license->load('plan.entitlements', 'servers', 'customer.user', 'subscription'),
        ]);
    }
}
