<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'server_hostname' => ['nullable', 'string', 'max:253', 'regex:/^[a-z0-9.-]+$/i'],
            'server_ip' => ['nullable', 'ip'],
            'nameservers' => ['nullable', 'string', 'max:500'],
            'licence_grace_days' => ['nullable', 'integer', 'min:0', 'max:120'],
            'suspend_on_expiry' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['suspend_on_expiry' => $this->boolean('suspend_on_expiry')]);
    }
}
