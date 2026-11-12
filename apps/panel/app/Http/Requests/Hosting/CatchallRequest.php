<?php

namespace App\Http\Requests\Hosting;

class CatchallRequest extends HostingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'domain_id' => ['required', 'integer'],
            'destination' => ['required', 'string', 'max:190', 'regex:/^(:fail:|[^@\s]+@[^@\s]+\.[a-z]{2,})$/i'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'destination.regex' => 'Enter an email address, or :fail: to reject unknown recipients.',
        ];
    }
}
