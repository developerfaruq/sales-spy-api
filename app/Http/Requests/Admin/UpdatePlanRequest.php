<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdatePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Every field is optional so a client can PATCH-style update one attribute
     * without having to resend the whole plan.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'slug' => [
                'sometimes',
                'string',
                'max:32',
                'lowercase',
                'regex:/^[a-z][a-z0-9-]*$/',
                Rule::unique('plans', 'slug')->ignore($this->route('planId')),
            ],
            'name' => ['sometimes', 'string', 'max:100'],
            'monthly_price' => ['sometimes', 'integer', 'min:0'],
            'yearly_price' => ['sometimes', 'integer', 'min:0'],
            'monthly_quota' => ['sometimes', 'integer', 'min:-1'],
            'access_rank' => ['sometimes', 'integer', 'min:0', 'max:255'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug must start with a letter and contain only lowercase letters, numbers and hyphens.',
            'slug.unique' => 'A plan with this slug already exists.',
            'monthly_quota.min' => 'Monthly quota must be -1 for unlimited, or 0 and above.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
