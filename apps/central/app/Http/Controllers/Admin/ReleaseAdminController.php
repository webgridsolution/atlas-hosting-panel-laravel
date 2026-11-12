<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Release;
use App\Models\ReleaseArtifact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReleaseAdminController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Release::class);

        return view('admin.releases.index', [
            'releases' => Release::with('artifacts')->latest()->paginate(20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Release::class);

        $data = $request->validate([
            'component' => ['required', Rule::in(['panel', 'agent', 'installer'])],
            'version' => ['required', 'string', 'max:20', 'regex:/^\d+\.\d+\.\d+$/'],
            'channel' => ['required', Rule::in(['stable', 'beta'])],
            'notes' => ['nullable', 'string', 'max:4000'],
            'is_security_release' => ['boolean'],
            'artifacts' => ['required', 'array', 'min:1'],
            'artifacts.*.os' => ['required', 'string', 'max:40'],
            'artifacts.*.arch' => ['required', 'string', 'max:16'],
            'artifacts.*.filename' => ['required', 'string', 'max:160'],
            'artifacts.*.size_bytes' => ['required', 'integer', 'min:1'],
            'artifacts.*.sha256' => ['required', 'string', 'size:64'],
            'artifacts.*.url' => ['required', 'url', 'max:500'],
            'artifacts.*.signature' => ['required', 'string'],
            'publish' => ['boolean'],
        ]);

        $release = Release::updateOrCreate(
            ['component' => $data['component'], 'version' => $data['version'], 'channel' => $data['channel']],
            [
                'notes' => $data['notes'] ?? null,
                'is_security_release' => (bool) ($data['is_security_release'] ?? false),
                'is_published' => (bool) ($data['publish'] ?? false),
                'published_at' => ($data['publish'] ?? false) ? now() : null,
            ],
        );

        foreach ($data['artifacts'] as $artifact) {
            $release->artifacts()->updateOrCreate(
                ['os' => $artifact['os'], 'arch' => $artifact['arch'], 'filename' => $artifact['filename']],
                [
                    'size_bytes' => $artifact['size_bytes'],
                    'sha256' => $artifact['sha256'],
                    'signature' => $artifact['signature'],
                    'url' => $artifact['url'],
                ],
            );
        }

        AuditLog::record('release.created', $release, ['version' => $release->version, 'published' => $release->is_published]);

        return back()->with('status', "Release {$release->component} {$release->version} saved.");
    }

    /**
     * Publishing means "customers may now download this", so the build has to
     * be real before the flag flips: at least one artifact, a well-formed
     * SHA-256 per artifact, and a file the download endpoint can actually
     * deliver. Flipping a boolean on an empty release is how a customer ends up
     * with a 404 on the one page that proves they bought something.
     */
    public function publish(Release $release): RedirectResponse
    {
        $this->authorize('update', $release);

        $artifacts = $release->artifacts()->get();

        if ($artifacts->isEmpty()) {
            return back()->with('error', 'This release has no artifacts yet — add a build before publishing.');
        }

        $broken = $artifacts->reject(fn (ReleaseArtifact $a) => $a->isServable());

        if ($broken->isNotEmpty()) {
            return back()->with('error', 'These artifacts have no downloadable file and no CDN URL: '
                .$broken->pluck('filename')->implode(', ')
                .'. Publish the build with: php artisan atlas:release:publish --file=… --publish');
        }

        $release->update(['is_published' => true, 'published_at' => $release->published_at ?? now()]);
        AuditLog::record('release.published', $release, ['version' => $release->version]);

        return back()->with('status', "{$release->component} {$release->version} is now published.");
    }
}
