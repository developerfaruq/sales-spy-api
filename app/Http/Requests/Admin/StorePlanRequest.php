<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StorePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            // Lowercase slug: the system resolves plans by slug and compares them
            // case-sensitively in several places.
            'slug' => ['required', 'string', 'max:32', 'lowercase', 'regex:/^[a-z][a-z0-9-]*$/', Rule::unique('plans', 'slug')],
            'name' => ['required', 'string', 'max:100'],
            // Cents. -1 quota means unlimited; anything below that is meaningless.
            'monthly_price' => ['required', 'integer', 'min:0'],
            'yearly_price' => ['required', 'integer', 'min:0'],
            'monthly_quota' => ['required', 'integer', 'min:-1'],
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

    public function bodyParameters(): array
    {
        return [
            'slug' => ['description' => 'Unique plan identifier.', 'example' => 'growth'],
            'name' => ['description' => 'Display name.', 'example' => 'Growth'],
            'monthly_price' => ['description' => 'Monthly price in cents.', 'example' => 4900],
            'yearly_price' => ['description' => 'Yearly price in cents.', 'example' => 49000],
            'monthly_quota' => ['description' => 'Monthly credits, or -1 for unlimited.', 'example' => 1000],
            'access_rank' => [
                'description' => 'Lead access tier. 0 = no contacts or products, 1 = contacts and products, 2 = deep scans. Defaults by slug, or 0 for a new slug.',
                'example' => 1,
            ],
            'features' => ['description' => 'Marketing feature bullets.', 'example' => ['1000 credits', 'Contact data']],
            'is_active' => ['description' => 'Whether the plan is publicly listed.', 'example' => true],
            'sort_order' => ['description' => 'Display order, ascending.', 'example' => 2],
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
