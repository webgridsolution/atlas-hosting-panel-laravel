<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServerController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Server::class);

        $status = (string) $request->query('status', '');

        return view('admin.servers.index', [
            'servers' => Server::query()
                ->with(['customer.user', 'license.plan'])
                ->when($status !== '', fn ($q) => $q->where('status', $status))
                ->latest()
                ->paginate(20)
                ->withQueryString(),
            'status' => $status,
        ]);
    }
}
