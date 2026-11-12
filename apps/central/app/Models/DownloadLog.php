<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DownloadLog extends Model
{
    protected $fillable = ['customer_id', 'artifact_id', 'ip_address'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(ReleaseArtifact::class, 'artifact_id');
    }
}
