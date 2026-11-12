<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDatabaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'min:3', 'max:48',
                'regex:/^[a-z][a-z0-9_]{2,47}$/',
                Rule::unique('hosting_databases', 'name'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Database names start with a letter and may contain lowercase letters, digits and underscores.',
        ];
    }
}
