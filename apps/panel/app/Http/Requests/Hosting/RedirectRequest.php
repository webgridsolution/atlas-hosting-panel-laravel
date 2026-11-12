<?php

namespace App\Http\Requests\Hosting;

class RedirectRequest extends HostingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'domain_id' => ['required', 'integer'],
            'source_path' => ['required', 'string', 'max:255', 'starts_with:/', 'not_regex:/\.\./'],
            'target_url' => ['required', 'url:http,https', 'max:512'],
            'status_code' => ['required', 'integer', 'in:301,302,307,308'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'source_path.starts_with' => 'The source path must start with a forward slash, for example /old-page.',
            'target_url.url' => 'The destination must be a full http:// or https:// URL.',
        ];
    }
}
