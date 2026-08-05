<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ReviewPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['approved', 'rejected'])],
            'rejection_reason' => [
                'nullable',
                'string',
                'max:1000',
                Rule::requiredIf($this->input('decision') === 'rejected'),
                Rule::prohibitedIf($this->input('decision') === 'approved'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'decision.in' => 'Decision must be approved or rejected.',
            'rejection_reason.required' => 'A rejection reason is required when rejecting a payment.',
            'rejection_reason.prohibited' => 'A rejection reason cannot be provided when approving a payment.',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'decision' => [
                'description' => 'The admin review decision. Accepted values: approved, rejected.',
                'example' => 'approved',
            ],
            'rejection_reason' => [
                'description' => 'Required when decision is rejected; prohibited when approved.',
                'example' => 'The submitted TXID could not be verified on TronScan.',
            ],
        ];
    }

    protected function failedValidation(Validator $validator): void
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
