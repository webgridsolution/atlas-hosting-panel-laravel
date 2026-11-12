<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\DownloadLog;
use App\Models\Release;
use App\Models\ReleaseArtifact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadController extends Controller
{
    public function index(Request $request): View
    {
        return view('customer.downloads', [
            'releases' => Release::where('is_published', true)
                ->with('artifacts')
                ->orderByRaw("case component when 'panel' then 1 when 'installer' then 2 else 3 end")
                ->latest('published_at')
                ->get(),
            'hasLicence' => $request->user()->customer->licenses()->active()->exists(),
        ]);
    }

    /**
     * Deliver one build.
     *
     * Three cases, in order:
     *   1. the vendor mirrors the file on a CDN  → redirect there;
     *   2. the file is on this server's disk      → stream it, with the exact
     *      filename and a no-store cache header so a download cannot be served
     *      from a shared proxy after the licence lapsed;
     *   3. neither                                → say so plainly. A 404 that
     *      looks like a broken link is worse than an explanation, because the
     *      customer then believes the product is broken rather than unpublished.
     *
     * Every successful hand-off is logged before the bytes move, so a download
     * that is interrupted still appears in the audit trail.
     */
    public function download(Request $request, ReleaseArtifact $artifact): RedirectResponse|StreamedResponse
    {
        abort_unless(
            $request->user()->customer->licenses()->active()->exists(),
            403,
            'An active licence is required to download builds.',
        );

        abort_unless($artifact->release?->is_published, 404);

        DownloadLog::create([
            'customer_id' => $request->user()->customer->id,
            'artifact_id' => $artifact->id,
            'ip_address' => $request->ip(),
        ]);

        $artifact->increment('download_count');

        // 1. Vendor-hosted build.
        if (filled($artifact->url)) {
            return redirect()->away($artifact->url);
        }

        // 2. Build stored on this server.
        $disk = Storage::disk(config('atlas.downloads.disk', 'local'));
        $path = trim((string) config('atlas.downloads.path', 'downloads'), '/').'/'.$artifact->filename;

        if ($disk->exists($path)) {
            return $disk->download($path, $artifact->filename, [
                'Content-Type' => 'application/gzip',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        // 3. Nothing to deliver.
        return redirect()->route('downloads.index')->with(
            'error',
            'That build is listed but its file is not on this server yet. '
            .'If you operate this site, publish it with: php artisan atlas:release:publish '
            .'--file=/path/to/'.$artifact->filename,
        );
    }
}
