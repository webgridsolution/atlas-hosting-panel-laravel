<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:64',
                Rule::unique('packages', 'name')->ignore($this->route('package')?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'disk_mb' => ['required', 'integer', 'min:256', 'max:10485760'],
            'inodes' => ['required', 'integer', 'min:1000', 'max:50000000'],
            'bandwidth_mb' => ['required', 'integer', 'min:1024', 'max:104857600'],
            'max_domains' => ['required', 'integer', 'min:1', 'max:100'],
            'max_databases' => ['required', 'integer', 'min:0', 'max:100'],
            'max_mailboxes' => ['required', 'integer', 'min:0', 'max:500'],
            'php_version' => ['required', Rule::in(['8.1', '8.2', '8.3', '8.4'])],
            'price_paise' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
