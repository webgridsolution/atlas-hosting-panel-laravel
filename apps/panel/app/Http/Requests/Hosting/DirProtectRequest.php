<?php

namespace App\Http\Requests\Hosting;

class DirProtectRequest extends HostingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'path' => ['required', 'string', 'max:255', 'not_regex:/\.\./', 'regex:/^[\/]?[a-zA-Z0-9._\/-]*$/'],
            'realm' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'min:3', 'max:32', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'path.regex' => 'Use a path made of letters, digits, dots, hyphens and slashes.',
            'password.confirmed' => 'The two passwords do not match.',
            'password.min' => 'Use at least 12 characters.',
        ];
    }
}
