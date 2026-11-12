<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => ['required', 'string', 'max:4096', 'regex:#^/home/[a-z][a-z0-9]{2,15}/#', 'not_regex:/\.\./'],
            'content' => ['nullable', 'string', 'max:2000000'],
            'append' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['append' => $this->boolean('append')]);
    }
}
