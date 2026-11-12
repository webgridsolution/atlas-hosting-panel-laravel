<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('plans.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'slug' => ['required', 'string', 'max:60', 'alpha_dash', Rule::unique('plans', 'slug')],
            'tagline' => ['nullable', 'string', 'max:160'],
            'edition' => ['required', 'string', 'max:40'],
            'is_active' => ['boolean'],
            'is_featured' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:999'],
            'price_inr' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'price_usd' => ['required', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'is_featured' => $this->boolean('is_featured'),
            'sort_order' => (int) ($this->input('sort_order') ?? 0),
        ]);
    }

    public function messages(): array
    {
        return [
            'price_inr.required' => 'The monthly price in INR is required — amounts are stored in minor units.',
        ];
    }
}
