<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('portal.access') ?? false;
    }

    public function rules(): array
    {
        return [
            'hostname' => [
                'required', 'string', 'max:253',
                'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i',
            ],
            'ip_address' => ['nullable', 'ip'],
            'os' => ['nullable', 'string', 'in:ubuntu-24.04,ubuntu-26.04,ubuntu-22.04'],
            'license_id' => ['nullable', 'integer', 'exists:licenses,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'hostname.regex' => 'Enter a fully-qualified hostname such as panel.example.com.',
        ];
    }
}
