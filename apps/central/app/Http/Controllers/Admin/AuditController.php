<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SecurityEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', AuditLog::class);

        return view('admin.audit.index', [
            'logs' => AuditLog::when($request->query('action'), fn ($q, $a) => $q->where('action', 'like', "%{$a}%"))
                ->latest('created_at')->paginate(30, ['*'], 'logs')->withQueryString(),
            'events' => SecurityEvent::latest()->paginate(15, ['*'], 'events'),
            'unresolved' => SecurityEvent::whereNull('resolved_at')->count(),
        ]);
    }

    public function resolve(SecurityEvent $event): RedirectResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $event->update(['resolved_at' => now()]);
        AuditLog::record('security_event.resolved', $event, ['type' => $event->type]);

        return back()->with('status', 'Security event marked resolved.');
    }
}
