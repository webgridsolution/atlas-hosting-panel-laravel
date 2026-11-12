<?php

namespace App\Http\Requests\Hosting;

class AddonDomainRequest extends HostingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'domain' => [
                'required', 'string', 'max:253',
                'regex:/^(?i)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/',
                'unique:domains,domain',
            ],
            'php_version' => ['nullable', 'in:'.implode(',', $this->phpVersions())],
            'with_mail' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'domain.regex' => 'Enter a full domain name such as shop.example.com.',
            'domain.unique' => 'That domain is already hosted on this server.',
        ];
    }
}
