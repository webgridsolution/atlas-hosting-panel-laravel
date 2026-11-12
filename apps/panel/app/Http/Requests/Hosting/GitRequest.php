<?php

namespace App\Http\Requests\Hosting;

class GitRequest extends HostingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'domain_id' => ['required', 'integer'],
            'path' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9._\/-]*$/i', 'not_regex:/\.\./'],
            'remote' => ['nullable', 'string', 'max:512', 'regex:/^(https:\/\/|ssh:\/\/|git@)[^\s]+$/'],
            'branch' => ['required', 'string', 'max:120', 'regex:/^[a-zA-Z0-9._\/-]+$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['remote.regex' => 'Use an https://, ssh:// or git@ remote. Nothing else is accepted.'];
    }
}
