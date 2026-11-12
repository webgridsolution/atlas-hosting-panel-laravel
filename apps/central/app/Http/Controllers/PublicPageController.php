<?php

namespace App\Http\Controllers;

use App\Models\Feature;
use App\Models\Plan;
use Illuminate\Http\Response;
use Illuminate\View\View;

class PublicPageController extends Controller
{
    public function home(): View
    {
        return view('public.home', [
            'plans' => Plan::active()->with(['prices', 'entitlements'])->get(),
        ]);
    }

    public function pricing(): View
    {
        return view('public.pricing', [
            'plans' => Plan::active()->with(['prices', 'entitlements'])->get(),
            'features' => Feature::orderBy('sort_order')->get(),
        ]);
    }

    public function docs(): View
    {
        return view('public.docs');
    }

    public function legal(): View
    {
        return view('public.legal');
    }

    /**
     * The bootstrap installer, served as a real file.
     *
     * The `__ATLAS_BASE__` placeholder is replaced at request time with the
     * origin the script was fetched from, so a self-hosted licence server, a
     * staging box and production all hand out a command that works against
     * themselves — no hard-coded vendor domain, no example.com.
     */
    public function installer(): Response
    {
        $script = (string) file_get_contents(resource_path('install/install.sh'));
        // config()'s default only applies to a missing key, and an unset
        // environment variable yields null — so `?:` is deliberate here.
        $base = rtrim((string) (config('atlas.downloads.base') ?: url('/')), '/');

        return response(
            str_replace('__ATLAS_BASE__', $base, $script),
            200,
            [
                'Content-Type' => 'text/x-shellscript; charset=utf-8',
                'Content-Disposition' => 'inline; filename="install.sh"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'public, max-age=300',
            ],
        );
    }
}
