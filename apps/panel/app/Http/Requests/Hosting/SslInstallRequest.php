<?php

namespace App\Http\Requests\Hosting;

class SslInstallRequest extends HostingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'domain_id' => ['required', 'integer'],
            // `contains` in Laravel 12+ tests array membership, not a substring,
            // so a PEM had to be matched with an explicit pattern.
            'cert_pem' => ['required', 'string', 'max:131072', 'regex:~-----BEGIN CERTIFICATE-----~'],
            'key_pem' => ['nullable', 'string', 'max:131072', 'regex:~-----BEGIN [A-Z ]*PRIVATE KEY-----~'],
            'ca_pem' => ['nullable', 'string', 'max:131072'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cert_pem.regex' => 'Paste the certificate in PEM format, including the BEGIN CERTIFICATE line.',
            'key_pem.regex' => 'Paste the private key in PEM format, including the BEGIN PRIVATE KEY line.',
        ];
    }
}
