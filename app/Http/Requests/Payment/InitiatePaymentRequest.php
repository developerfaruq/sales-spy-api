<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class InitiatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan_slug'     => ['required', 'string', 'exists:plans,slug'],
            'billing_cycle' => ['required', 'string', 'in:monthly,yearly'],
        ];
    }

    public function messages(): array
    {
        return [
            'plan_slug.exists'        => 'The selected plan does not exist.',
            'billing_cycle.in'        => 'Billing cycle must be monthly or yearly.',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'plan_slug'     => ['description' => 'The plan to subscribe to.', 'example' => 'pro'],
            'billing_cycle' => ['description' => 'Monthly or yearly billing.', 'example' => 'monthly'],
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}
