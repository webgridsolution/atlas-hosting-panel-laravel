<?php

namespace App\Http\Requests\Hosting;

class RemoteMysqlRequest extends HostingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'host' => ['required', 'string', 'max:43', 'regex:/^([0-9.]+\/[0-9]{1,2}|[0-9.]+|[0-9a-f:]+)$/i'],
            'label' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['host.regex' => 'Enter an IP address or CIDR block, not a hostname.'];
    }
}
