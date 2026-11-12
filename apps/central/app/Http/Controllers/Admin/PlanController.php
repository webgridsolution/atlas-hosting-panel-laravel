<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlanRequest;
use App\Http\Requests\UpdatePlanRequest;
use App\Models\Feature;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Plan::class);

        return view('admin.plans.index', [
            'plans' => Plan::with(['prices', 'entitlements'])->withCount('licenses')->orderBy('sort_order')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Plan::class);

        return view('admin.plans.create', [
            'features' => Feature::orderBy('sort_order')->get(),
            'plan' => new Plan(['is_active' => true, 'sort_order' => 10]),
        ]);
    }

    public function store(StorePlanRequest $request): RedirectResponse
    {
        $plan = DB::transaction(function () use ($request) {
            $plan = Plan::create($request->safe()->only([
                'name', 'slug', 'tagline', 'edition', 'is_active', 'is_featured', 'sort_order',
            ]));

            $this->syncPrices($plan, (float) $request->input('price_inr'), (float) $request->input('price_usd'));
            $this->syncEntitlements($plan, $request->input('entitlements', []));

            return $plan;
        });

        return redirect()->route('admin.plans.index')->with('status', "Plan {$plan->name} created.");
    }

    public function edit(Plan $plan): View
    {
        $this->authorize('update', $plan);

        return view('admin.plans.edit', [
            'plan' => $plan->load(['prices', 'entitlements']),
            'features' => Feature::orderBy('sort_order')->get(),
        ]);
    }

    public function update(UpdatePlanRequest $request, Plan $plan): RedirectResponse
    {
        DB::transaction(function () use ($request, $plan) {
            $plan->update($request->safe()->only([
                'name', 'slug', 'tagline', 'edition', 'is_active', 'is_featured', 'sort_order',
            ]));

            $this->syncPrices($plan, (float) $request->input('price_inr'), (float) $request->input('price_usd'));
            $this->syncEntitlements($plan, $request->input('entitlements', []));
        });

        return redirect()->route('admin.plans.index')->with('status', "Plan {$plan->name} updated.");
    }

    /** Amounts arrive as major units from the form and are stored in minor units. */
    private function syncPrices(Plan $plan, float $inr, float $usd): void
    {
        foreach (['INR' => $inr, 'USD' => $usd] as $currency => $amount) {
            $plan->prices()->updateOrCreate(
                ['interval' => 'monthly', 'currency' => $currency],
                ['amount_minor' => (int) round($amount * 100), 'is_active' => true],
            );
        }
    }

    private function syncEntitlements(Plan $plan, array $values): void
    {
        $featureKeys = Feature::pluck('key');

        foreach ($values as $key => $value) {
            if (! $featureKeys->contains($key)) {
                continue; // ignore anything not in the registry
            }

            $plan->entitlements()->updateOrCreate(
                ['feature_key' => $key],
                ['value' => (int) $value],
            );
        }
    }
}
