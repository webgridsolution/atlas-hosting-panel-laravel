<?php

namespace App\Http\Controllers\Whm;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePackageRequest;
use App\Models\Package;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PackageController extends Controller
{
    public function index(): View
    {
        return view('whm.packages.index', [
            'packages' => Package::orderBy('name')->withCount('accounts')->get(),
        ]);
    }

    public function store(StorePackageRequest $request): RedirectResponse
    {
        Package::create($request->validated());

        return back()->with('status', 'Package created.');
    }

    public function update(StorePackageRequest $request, Package $package): RedirectResponse
    {
        $package->update($request->validated());

        return back()->with('status', "Package {$package->name} updated.");
    }

    public function destroy(Request $request, Package $package): RedirectResponse
    {
        if ($package->accounts()->exists()) {
            return back()->with('error', 'This package is in use. Deactivate it instead of deleting it.');
        }

        $package->delete();

        return back()->with('status', 'Package deleted.');
    }
}
