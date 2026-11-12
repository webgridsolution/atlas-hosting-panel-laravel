<?php

namespace App\Http\Requests\Hosting;

class ErrorPageRequest extends HostingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'domain_id' => ['required', 'integer'],
            'status_code' => ['required', 'integer', 'in:400,401,403,404,500,502,503,504'],
            'body' => ['required', 'string', 'max:262144'],
        ];
    }
}
