<?php

namespace App\Http\Requests\Hosting;

class MimeRequest extends HostingRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'extension' => ['required', 'string', 'max:16', 'regex:/^\.?[a-z0-9][a-z0-9+.-]*$/i'],
            'mime_type' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9!#$&^_.+-]*\/[a-z0-9][a-z0-9!#$&^_.+-]*$/i'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['mime_type.regex' => 'A MIME type looks like image/webp or application/pdf.'];
    }
}
