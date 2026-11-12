<?php

namespace App\Http\Requests\Hosting;

class ApplicationRequest extends HostingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'domain_id' => ['required', 'integer'],
            'slug' => ['required', 'string', 'in:'.implode(',', array_keys(config('atlas.apps', [])))],
            'path' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'admin_user' => ['required', 'string', 'min:3', 'max:32', 'alpha_dash'],
            'admin_email' => ['required', 'email:rfc', 'max:190'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['slug.in' => 'Choose an application from the catalogue.'];
    }
}
