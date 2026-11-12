<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ReleaseArtifact extends Model
{
    protected $fillable = ['release_id', 'os', 'arch', 'filename', 'size_bytes', 'sha256', 'signature', 'url', 'download_count'];

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /**
     * Whether the download endpoint can actually hand this build to a customer.
     *
     * The three cases in `DownloadController::download()` are the definition, and
     * this method is deliberately the same list in the same order: a CDN URL, a
     * file on the delivery disk, or nothing. Publishing must not be possible
     * until at least one artifact answers true, because a published release with
     * a dead download link is the one failure a customer will always notice.
     *
     * A checksum is part of "servable" too: the installer refuses to run a
     * download it cannot verify, so an artifact without a well-formed SHA-256 is
     * not deliverable even when the bytes are there.
     */
    public function isServable(): bool
    {
        if (! $this->hasValidChecksum()) {
            return false;
        }

        if (filled($this->url)) {
            return true;
        }

        return Storage::disk(config('atlas.downloads.disk', 'local'))->exists($this->storagePath());
    }

    /** Where the delivery disk would hold this build. */
    public function storagePath(): string
    {
        return trim((string) config('atlas.downloads.path', 'downloads'), '/').'/'.$this->filename;
    }

    private function hasValidChecksum(): bool
    {
        return is_string($this->sha256)
            && strlen($this->sha256) === 64
            && ctype_xdigit($this->sha256);
    }
}
