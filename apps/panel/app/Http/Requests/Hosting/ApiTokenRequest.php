<?php

namespace App\Http\Requests\Hosting;

class ApiTokenRequest extends HostingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'abilities' => ['array', 'max:10'],
            'abilities.*' => ['in:read,write,dns,email,files'],
            'expires_in_days' => ['required', 'integer', 'in:30,90,180,365'],
        ];
    }
}
