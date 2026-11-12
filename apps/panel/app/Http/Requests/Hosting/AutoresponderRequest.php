<?php

namespace App\Http\Requests\Hosting;

class AutoresponderRequest extends HostingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'domain_id' => ['required', 'integer'],
            'mailbox' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]{0,63}$/'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:65535'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['ends_at.after_or_equal' => 'The end date must not be before the start date.'];
    }
}
