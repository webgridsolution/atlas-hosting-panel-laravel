<?php

namespace App\Http\Controllers\Whm;

use App\Http\Controllers\Controller;
use App\Models\Operation;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $op = trim((string) $request->query('op'));
        $status = (string) $request->query('status');

        return view('whm.audit.index', [
            'operations' => Operation::with(['user', 'account'])
                ->when($op !== '', fn ($q) => $q->where('op', 'like', "%{$op}%"))
                ->when(in_array($status, ['ok', 'error', 'denied'], true), fn ($q) => $q->where('status', $status))
                ->orderByDesc('id')
                ->paginate(40)
                ->withQueryString(),
            'op' => $op,
            'status' => $status,
        ]);
    }
}
