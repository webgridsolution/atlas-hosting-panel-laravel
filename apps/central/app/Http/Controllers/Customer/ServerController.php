<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServerRequest;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServerController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Server::class);

        $servers = Server::query()
            ->where('customer_id', $request->user()->customer->id)
            ->with('license.plan')
            ->latest()
            ->paginate(15);

        return view('customer.servers.index', ['servers' => $servers]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Server::class);

        return view('customer.servers.create', [
            'licenses' => $request->user()->customer->licenses()->active()->with('plan')->get(),
        ]);
    }

    public function store(StoreServerRequest $request): RedirectResponse
    {
        $customer = $request->user()->customer;

        if ($request->filled('license_id')) {
            // Ownership check before attaching — never trust the posted id.
            $owned = $customer->licenses()->whereKey($request->integer('license_id'))->exists();
            abort_unless($owned, 403);
        }

        $server = $customer->servers()->create([
            ...$request->safe()->only(['hostname', 'ip_address', 'os', 'license_id']),
            'status' => 'pending',
        ]);

        return redirect()
            ->route('customer.servers.index')
            ->with('status', "Server {$server->hostname} registered. Run the installer to complete activation.");
    }

    public function destroy(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('delete', $server);

        $server->update(['status' => 'decommissioned']);

        return redirect()
            ->route('customer.servers.index')
            ->with('status', "{$server->hostname} marked as decommissioned.");
    }
}
