<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Customer::class);

        $search = trim((string) $request->query('q'));

        $customers = Customer::query()
            ->with('user')
            ->withCount(['licenses', 'servers'])
            ->when($search !== '', function ($query) use ($search) {
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })->orWhere('company_name', 'like', "%{$search}%");
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.customers.index', ['customers' => $customers, 'search' => $search]);
    }

    public function show(Customer $customer): View
    {
        $this->authorize('view', $customer);

        return view('admin.customers.show', [
            'customer' => $customer->load(['user', 'licenses.plan', 'servers']),
        ]);
    }
}
