<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhpSetting extends Model
{
    /**
     * Directives a customer may change. Anything else would either be ignored
     * by the pool or stop it from starting, so the form and the manager both
     * intersect the input with this list.
     */
    public const EDITABLE = [
        'memory_limit',
        'upload_max_filesize',
        'post_max_size',
        'max_execution_time',
        'max_input_vars',
        'max_input_time',
        'display_errors',
        'allow_url_fopen',
        'date.timezone',
    ];

    protected $fillable = ['account_id', 'domain_id', 'key', 'value'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
