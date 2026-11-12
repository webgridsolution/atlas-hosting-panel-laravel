<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller authorises the account
    }

    public function rules(): array
    {
        return [
            'domain' => [
                'required', 'string', 'max:253',
                'regex:/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i',
                Rule::unique('domains', 'domain'),
                Rule::unique('accounts', 'domain'),
            ],
            'document_root' => ['nullable', 'string', 'max:512', 'regex:#^/home/[a-z][a-z0-9]{2,15}/[A-Za-z0-9_./-]*$#'],
        ];
    }

    public function messages(): array
    {
        return [
            'domain.regex' => 'Enter a valid domain name, for example shop.acme.co.in.',
            'document_root.regex' => 'The document root must live inside the account home directory.',
        ];
    }
}
