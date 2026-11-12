<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendMailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to' => ['required', 'email', 'max:320'],
            'subject' => ['required', 'string', 'max:250', 'not_regex:/[\r\n]/'],
            'body' => ['required', 'string', 'max:100000'],
        ];
    }
}
