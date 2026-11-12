<?php

namespace App\Http\Requests\Hosting;

class FtpAccountRequest extends HostingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:24', 'regex:/^[a-z0-9_-]+$/'],
            'directory' => ['nullable', 'string', 'max:255', 'not_regex:/\.\./'],
            'quota_mb' => ['required', 'integer', 'min:0', 'max:10485760'],
            'read_only' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.regex' => 'Use lowercase letters, digits, hyphens and underscores for the FTP account name.',
            'directory.not_regex' => 'The directory may not point outside your account.',
        ];
    }
}
