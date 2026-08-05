<?php

namespace App\Http\Requests\Admin;

use App\Enums\PaymentStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ListPaymentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(PaymentStatus::class)],
            'plan' => ['sometimes', 'string', 'exists:plans,slug'],
            'search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function queryParameters(): array
    {
        return [
            'status' => [
                'description' => 'Filter by payment status.',
                'example' => 'awaiting_verification',
            ],
            'plan' => [
                'description' => 'Filter by plan slug.',
                'example' => 'pro',
            ],
            'search' => [
                'description' => 'Search by order reference, TXID, user name, or user email.',
                'example' => 'SPY-2026',
            ],
            'per_page' => [
                'description' => 'Results per page. Maximum 100.',
                'example' => 25,
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
