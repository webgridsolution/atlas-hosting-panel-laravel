<?php

namespace App\Http\Requests\Hosting;

class ParkedDomainRequest extends HostingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'target_id' => ['required', 'integer'],
            'domain' => [
                'required', 'string', 'max:253',
                'regex:/^(?i)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/',
                'unique:domains,domain',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['domain.unique' => 'That alias is already registered on this server.'];
    }
}
