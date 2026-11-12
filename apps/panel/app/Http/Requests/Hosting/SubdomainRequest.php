<?php

namespace App\Http\Requests\Hosting;

class SubdomainRequest extends HostingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'parent_id' => ['required', 'integer'],
            'label' => [
                'required', 'string', 'min:1', 'max:63',
                'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/',
                'not_in:www,mail,ftp,localhost,webmail,cpanel,whm,ns1,ns2,autodiscover',
            ],
            'own_root' => ['boolean'],
            'php_version' => ['nullable', 'in:'.implode(',', $this->phpVersions())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'label.regex' => 'A subdomain may use lowercase letters, digits and hyphens, and may not start or end with a hyphen.',
            'label.not_in' => 'That label is reserved for a system service. Choose another.',
        ];
    }
}
