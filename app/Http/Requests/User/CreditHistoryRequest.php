<?php

namespace App\Http\Requests\User;

use App\Enums\CreditTransactionType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CreditHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', Rule::enum(CreditTransactionType::class)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function queryParameters(): array
    {
        return [
            'type' => [
                'description' => 'Filter transactions by type.',
                'example' => 'spend',
            ],
            'from' => [
                'description' => 'Include transactions on or after this date (YYYY-MM-DD).',
                'example' => '2026-08-01',
            ],
            'to' => [
                'description' => 'Include transactions on or before this date (YYYY-MM-DD).',
                'example' => '2026-08-31',
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
