<?php

namespace App\Http\Requests\Hosting;

class IpBlockRequest extends HostingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cidr' => ['required', 'string', 'max:43', 'regex:/^([0-9.]+\/[0-9]{1,2}|[0-9a-f:]+\/[0-9]{1,3}|[0-9.]+|[0-9a-f:]{2,})$/i'],
            'reason' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cidr.regex' => 'Enter an IPv4 or IPv6 address, optionally with a /mask.',
            'expires_at.after' => 'An expiry date must be in the future.',
        ];
    }
}
