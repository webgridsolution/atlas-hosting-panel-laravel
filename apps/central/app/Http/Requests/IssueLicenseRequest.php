<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IssueLicenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('licenses.issue') ?? false;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'interval' => ['required', 'in:monthly,quarterly,half_yearly,annual,lifetime'],
        ];
    }
}
