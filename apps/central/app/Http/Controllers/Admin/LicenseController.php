<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Licensing\IssueLicense;
use App\Enums\LicenseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\IssueLicenseRequest;
use App\Models\Customer;
use App\Models\License;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LicenseController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', License::class);

        $status = (string) $request->query('status', '');

        $licenses = License::query()
            ->with(['customer.user', 'plan', 'servers'])
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.licenses.index', [
            'licenses' => $licenses,
            'status' => $status,
            'statuses' => LicenseStatus::options(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('issue', License::class);

        return view('admin.licenses.create', [
            'customers' => Customer::with('user')->orderBy('id')->get(),
            'plans' => Plan::active()->with('prices')->get(),
        ]);
    }

    public function store(IssueLicenseRequest $request, IssueLicense $issueLicense): RedirectResponse
    {
        $license = $issueLicense->handle(
            Customer::findOrFail($request->integer('customer_id')),
            Plan::findOrFail($request->integer('plan_id')),
            $request->string('interval')->toString(),
        );

        return redirect()
            ->route('admin.licenses.show', $license)
            ->with('status', 'Licence issued. The key is shown once on this screen — deliver it to the customer.')
            ->with('issued_key', $license->key);
    }

    public function show(Request $request, License $license): View
    {
        $this->authorize('view', $license);

        return view('admin.licenses.show', [
            'license' => $license->load(['customer.user', 'plan.entitlements', 'servers']),
            'revealedKey' => $request->session()->get('issued_key'),
        ]);
    }

    public function updateStatus(Request $request, License $license): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                LicenseStatus::Active->value,
                LicenseStatus::Suspended->value,
                LicenseStatus::Revoked->value,
            ])],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $target = LicenseStatus::from($validated['status']);

        // Policy split: revoking needs a stronger permission than suspending.
        $this->authorize($target === LicenseStatus::Revoked ? 'revoke' : 'suspend', $license);

        $license->update(['status' => $target]);

        logger()->warning('licence.status_changed', [
            'license' => $license->public_id,
            'to' => $target->value,
            'by' => $request->user()->id,
            'reason' => $validated['reason'],
        ]);

        return back()->with('status', "Licence {$license->public_id} is now {$target->label()}.");
    }
}
