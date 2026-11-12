<?php

namespace App\Http\Requests;

use App\Models\DnsRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDnsRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'zone_id' => ['required', 'integer', Rule::exists('dns_zones', 'id')],
            'name' => ['required', 'string', 'max:253', 'regex:/^(@|(\*\.)?[a-z0-9_][a-z0-9_.-]*)$/i'],
            'type' => ['required', Rule::in(DnsRecord::TYPES)],
            'content' => ['required', 'string', 'max:2000'],
            'ttl' => ['required', 'integer', 'min:60', 'max:604800'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Use "@" for the domain itself, or a hostname such as www or _dmarc.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => strtolower(trim((string) $this->input('name')))]);
    }
}
