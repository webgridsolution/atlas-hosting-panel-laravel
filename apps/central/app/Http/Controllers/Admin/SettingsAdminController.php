<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsAdminController extends Controller
{
    /** Keys the console may edit, with their type. */
    private const EDITABLE = [
        'company.name' => ['label' => 'Legal entity name', 'group' => 'company', 'type' => 'string'],
        'company.gstin' => ['label' => 'Seller GSTIN', 'group' => 'company', 'type' => 'string'],
        'company.address' => ['label' => 'Registered address', 'group' => 'company', 'type' => 'string'],
        'billing.seller_state' => ['label' => 'Seller state code (e.g. JH, KA)', 'group' => 'billing', 'type' => 'string'],
        'license.grace_days' => ['label' => 'Default licence grace (days)', 'group' => 'licensing', 'type' => 'integer'],
        'support.email' => ['label' => 'Support inbox', 'group' => 'support', 'type' => 'string'],
        'downloads.base_url' => ['label' => 'Artifact CDN base URL', 'group' => 'releases', 'type' => 'string'],
    ];

    public function index(): View
    {
        $this->authorize('viewAny', Setting::class);

        return view('admin.settings.index', [
            'fields' => collect(self::EDITABLE)->map(fn ($meta, $key) => [
                'key' => $key,
                'label' => $meta['label'],
                'group' => $meta['group'],
                'type' => $meta['type'],
                'value' => Setting::get($key, ''),
            ]),
            'canManage' => auth()->user()->can('settings.manage'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('update', Setting::class);

        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*' => ['nullable', 'string', 'max:500'],
        ]);

        foreach (self::EDITABLE as $key => $meta) {
            if (! array_key_exists($key, $data['settings'])) {
                continue;
            }

            Setting::put($key, $data['settings'][$key], $meta['group'], $meta['type'], $meta['label']);
        }

        AuditLog::record('settings.updated', null, ['keys' => array_keys($data['settings'])]);

        return back()->with('status', 'Settings saved.');
    }
}
