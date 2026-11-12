<?php

namespace App\Http\Requests\Hosting;

class ForwarderRequest extends HostingRequest
{
    protected function prepareForValidation(): void
    {
        // The field is a textarea: one address per line, or comma separated.
        $raw = $this->input('destination');

        if (is_string($raw)) {
            $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];
            $this->merge(['destinations' => array_values(array_filter($parts))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'domain_id' => ['required', 'integer'],
            'source' => ['required', 'string', 'max:64', 'regex:/^([a-z0-9][a-z0-9._-]{0,63}|\*)$/'],
            'destinations' => ['required', 'array', 'min:1', 'max:20'],
            'destinations.*' => ['email:rfc', 'max:190'],
            'keep_copy' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'source.regex' => 'The source must be a local part such as "sales".',
            'destinations.*.email' => 'Every destination must be a valid email address.',
        ];
    }
}
