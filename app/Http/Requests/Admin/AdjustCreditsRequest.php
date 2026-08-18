<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AdjustCreditsRequest extends FormRequest
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
            // Grants only. CreditService::add rejects non-positive amounts, and a
            // debit path would need to define what happens when the balance is
            // already lower than the deduction. Change the user's plan to reduce
            // an allowance instead.
            'amount' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min' => 'Amount must be a positive number of credits to grant.',
            'reason.required' => 'A reason is required so the adjustment is auditable.',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'amount' => ['description' => 'Credits to grant.', 'example' => 100],
            'reason' => [
                'description' => 'Why the adjustment was made. Stored on the ledger entry and the audit log.',
                'example' => 'Goodwill credit for the failed scan on 12 Aug',
            ],
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
