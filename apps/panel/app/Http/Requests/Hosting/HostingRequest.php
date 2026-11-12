<?php

namespace App\Http\Requests\Hosting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared behaviour for every hosting-panel form: the actor must own an
 * account. Authorisation of the *record* is the policy's job; this only
 * answers "is this a hosting customer at all".
 */
abstract class HostingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->account() !== null;
    }

    /** @return list<string> */
    protected function phpVersions(): array
    {
        return config('atlas.php.versions', ['8.3']);
    }
}
