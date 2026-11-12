<?php

namespace App\Http\Controllers\Whm;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSettingsRequest;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(): View
    {
        return view('whm.settings.index', [
            'settings' => [
                'server_hostname' => Setting::get('server_hostname', gethostname() ?: ''),
                'server_ip' => Setting::get('server_ip', ''),
                'nameservers' => Setting::get('nameservers', implode(',', (array) config('atlas.dns.nameservers'))),
                'licence_grace_days' => Setting::get('licence_grace_days', config('atlas.central.grace_days')),
                'suspend_on_expiry' => Setting::get('suspend_on_expiry', '0'),
                'server_id' => Setting::get('server_id', (string) config('atlas.central.server_id')),
            ],
            'ports' => config('atlas.ports'),
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $data = $request->validated();

        foreach (['server_hostname', 'server_ip', 'nameservers'] as $key) {
            if (array_key_exists($key, $data)) {
                Setting::put($key, (string) ($data[$key] ?? ''));
            }
        }

        if (array_key_exists('licence_grace_days', $data)) {
            Setting::put('licence_grace_days', (string) (int) $data['licence_grace_days']);
        }

        Setting::put('suspend_on_expiry', $data['suspend_on_expiry'] ? '1' : '0');

        cache()->forget('licence:evaluate');

        return back()->with('status', 'Settings saved. Changes take effect immediately.');
    }
}
