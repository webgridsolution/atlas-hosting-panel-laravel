<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Release;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReleaseController extends Controller
{
    public function latest(Request $request): JsonResponse|Response
    {
        $component = $request->string('component', 'panel')->toString();
        $channel = $request->string('channel', 'stable')->toString();

        $release = Release::query()
            ->where('component', $component)
            ->where('channel', $channel)
            ->where('is_published', true)
            ->with('artifacts')
            ->latest('published_at')
            ->first();

        if (! $release) {
            return response()->json(['error' => ['code' => 'NO_RELEASE', 'message' => 'No published release for this component and channel.']], 404);
        }

        // A shell installer cannot parse JSON without jq, and a fresh Ubuntu
        // image does not ship jq. `format=tsv` is the same data as one
        // tab-separated line per artifact, which awk reads without helpers.
        if ($request->string('format')->toString() === 'tsv') {
            $rows = $release->artifacts->map(fn ($a) => implode("\t", [
                $a->os, $a->arch, $a->filename, $a->sha256, (string) $a->size_bytes, $a->url,
            ]))->implode("\n");

            return response($rows."\n", 200, ['Content-Type' => 'text/tab-separated-values']);
        }

        return response()->json([
            'component' => $release->component,
            'version' => $release->version,
            'channel' => $release->channel,
            'security' => $release->is_security_release,
            'published_at' => $release->published_at?->toIso8601String(),
            'notes' => $release->notes,
            'artifacts' => $release->artifacts->map(fn ($a) => [
                'os' => $a->os,
                'arch' => $a->arch,
                'filename' => $a->filename,
                'sha256' => $a->sha256,
                'size' => $a->size_bytes,
                'signature' => $a->signature,
                'url' => $a->url,
            ])->all(),
            'manifest_version' => 1,
        ]);
    }
}
