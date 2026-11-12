<?php

namespace App\Http\Requests;

use App\Models\Package;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyRole(['admin', 'reseller']);
    }

    public function rules(): array
    {
        $weakNames = ['root', 'admin', 'atlas', 'mysql', 'www', 'ftp', 'mail', 'nobody', 'daemon', 'ssh', 'postfix', 'dovecot'];

        return [
            'username' => [
                'required', 'string', 'min:3', 'max:16', 'regex:/^[a-z][a-z0-9]{2,15}$/',
                Rule::notIn($weakNames),
                Rule::unique('accounts', 'username')->whereNull('deleted_at'),
            ],
            'domain' => [
                'required', 'string', 'max:253', 'regex:/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i',
                Rule::unique('accounts', 'domain')->whereNull('deleted_at'),
                Rule::unique('domains', 'domain'),
            ],
            'package_id' => ['required', 'integer', Rule::exists('packages', 'id')->where('is_active', true)],
            'owner_email' => ['nullable', 'email', 'max:190'],
            'owner_name' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.regex' => 'The username must start with a letter and contain only lowercase letters and digits (3–16 characters).',
            'domain.regex' => 'Enter a valid domain name, for example acme.co.in.',
            'username.not_in' => 'That username is reserved by the operating system.',
        ];
    }

    public function package(): Package
    {
        return Package::findOrFail($this->integer('package_id'));
    }
}
