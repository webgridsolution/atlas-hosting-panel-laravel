<?php

namespace App\Http\Requests\Hosting;

class MailingListRequest extends HostingRequest
{
    protected function prepareForValidation(): void
    {
        $raw = $this->input('members');

        if (is_string($raw)) {
            $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];
            $this->merge(['member_list' => array_values(array_filter($parts))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'domain_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'min:2', 'max:32', 'regex:/^[a-z][a-z0-9-]*$/'],
            'title' => ['nullable', 'string', 'max:120'],
            'member_list' => ['array', 'max:1000'],
            'member_list.*' => ['email:rfc'],
        ];
    }
}
