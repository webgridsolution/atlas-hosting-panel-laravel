<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreMailboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'local_part' => [
                'required', 'string', 'min:1', 'max:64',
                'regex:/^[a-z0-9]([a-z0-9._-]*[a-z0-9])?$/',
            ],
            'password' => ['required', 'string', Password::min(12)->letters()->mixedCase()->numbers()],
            'quota_mb' => ['required', 'integer', 'min:50', 'max:51200'],
            'domain' => ['nullable', 'string', Rule::exists('domains', 'domain')],
        ];
    }

    public function messages(): array
    {
        return [
            'local_part.regex' => 'Use lowercase letters, digits, dots, dashes or underscores.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('local_part')) {
            $this->merge(['local_part' => strtolower((string) $this->input('local_part'))]);
        }
    }
}
