<?php

namespace App\Console\Commands;

use App\Models\Release;
use App\Models\ReleaseArtifact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Publishes a real build.
 *
 * The checksum and size are computed from the file, never typed by hand: a
 * stale or invented SHA-256 is indistinguishable from a tampered download to
 * the installer, which fails closed and leaves the customer with a machine that
 * will not install. This command is the only supported way to put a build in
 * front of customers.
 *
 *   php artisan atlas:release:publish \
 *       --file=storage/app/releases/atlas-panel-0.9.1-linux-amd64.tar.gz \
 *       --version=0.9.1 --os=ubuntu-24.04 --arch=amd64 --notes="…"
 *
 * The file is copied into the download disk (or left in place with --in-place
 * when an external CDN serves it), the artifact row is written, and the release
 * is marked published only after the copy is verified byte-for-byte.
 */
class PublishReleaseCommand extends Command
{
    protected $signature = 'atlas:release:publish
                            {--file= : Path to the build archive}
                            {--version= : Version being published, e.g. 0.9.1}
                            {--component=panel : panel|installer|agent}
                            {--os=ubuntu-24.04 : ubuntu-22.04|ubuntu-24.04}
                            {--arch=amd64 : amd64|arm64}
                            {--channel=stable : stable|beta|security}
                            {--notes= : Release notes shown to customers}
                            {--signature= : Detached ed25519 signature (base64) from the release key}
                            {--url= : External URL when a CDN serves the file}
                            {--in-place : Do not copy; assume the file is already on the download disk}
                            {--publish : Mark the release published (omit for a draft)}';

    protected $description = 'Register a build with its computed SHA-256 and publish it to customers';

    public function handle(): int
    {
        $file = (string) $this->option('file');
        $version = (string) $this->option('version');

        if ($file === '' || ! is_file($file)) {
            $this->error('--file must point at an existing build archive.');

            return self::FAILURE;
        }

        if ($version === '' || preg_match('/^\d+\.\d+\.\d+(-[a-z0-9.]+)?$/', $version) !== 1) {
            $this->error('--version must look like 1.2.3 or 1.2.3-rc.1.');

            return self::FAILURE;
        }

        $os = (string) $this->option('os');
        $arch = (string) $this->option('arch');

        if (! in_array($os, ['ubuntu-22.04', 'ubuntu-24.04'], true)) {
            $this->error('Only validated platforms may be published (ubuntu-22.04, ubuntu-24.04).');

            return self::FAILURE;
        }

        if (! in_array($arch, ['amd64', 'arm64'], true)) {
            $this->error('--arch must be amd64 or arm64.');

            return self::FAILURE;
        }

        $filename = basename($file);
        $sha256 = hash_file('sha256', $file);
        $size = filesize($file);

        if ($sha256 === false || $size === false) {
            $this->error('The build could not be read.');

            return self::FAILURE;
        }

        $disk = Storage::disk(config('atlas.downloads.disk', 'local'));
        $path = trim((string) config('atlas.downloads.path', 'downloads'), '/').'/'.$filename;

        if (! $this->option('in-place') && $this->option('url') === null) {
            if (! $disk->put($path, fopen($file, 'rb'))) {
                $this->error("Could not copy the build to the download disk at {$path}.");

                return self::FAILURE;
            }

            // Verify the copy, not the source: a truncated upload is the failure
            // mode that matters here.
            if ($disk->exists($path) && hash_file('sha256', $disk->path($path)) !== $sha256) {
                $disk->delete($path);
                $this->error('The copied build does not match the source checksum; nothing was published.');

                return self::FAILURE;
            }

            $this->line("  stored  <info>{$path}</info>");
        }

        $release = Release::firstOrCreate(
            [
                'component' => (string) $this->option('component'),
                'version' => $version,
                'channel' => (string) $this->option('channel'),
            ],
            [
                'notes' => (string) $this->option('notes'),
                'is_security_release' => $this->option('channel') === 'security',
                'is_published' => false,
                'published_at' => null,
            ],
        );

        ReleaseArtifact::updateOrCreate(
            ['release_id' => $release->id, 'os' => $os, 'arch' => $arch],
            [
                'filename' => $filename,
                'size_bytes' => $size,
                'sha256' => $sha256,
                'signature' => $this->option('signature'),
                'url' => $this->option('url'),
            ],
        );

        if ($this->option('publish')) {
            $release->forceFill([
                'is_published' => true,
                'published_at' => $release->published_at ?? now(),
            ])->save();
        }

        $this->newLine();
        $this->table(
            ['Field', 'Value'],
            [
                ['Release', $release->component.' v'.$release->version.' ('.$release->channel.')'],
                ['Platform', $os.' / '.$arch],
                ['File', $filename],
                ['Size', number_format($size / 1048576, 1).' MB ('.number_format($size).' bytes)'],
                ['SHA-256', $sha256],
                ['Signature', $this->option('signature') ?: 'none supplied'],
                ['Published', $release->is_published ? 'yes' : 'no — draft, hidden from customers'],
            ],
        );

        if (! $release->is_published) {
            $this->comment('Re-run with --publish once the build is on the download disk and mirrored.');
        } elseif (blank($this->option('signature'))) {
            $this->warn('Published without a detached signature. The installer will verify the checksum only.');
        }

        return self::SUCCESS;
    }
}
