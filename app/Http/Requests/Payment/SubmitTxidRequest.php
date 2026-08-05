<?php

namespace App\Http\Requests\Payment;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class SubmitTxidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'txid' => [
                'required',
                'string',
                'min:20',   // TRC20 TXIDs are 64 characters
                'max:100',
                'regex:/^[a-fA-F0-9]+$/', // Only hex characters
                Rule::unique('payment_orders', 'txid')->ignore($this->route('orderId')),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'txid.regex' => 'The transaction ID must be a valid hexadecimal string.',
            'txid.min' => 'The transaction ID is too short to be valid.',
            'txid.unique' => 'This transaction ID has already been submitted for another payment.',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'txid' => [
                'description' => 'The blockchain transaction hash from TronScan.',
                'example' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
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
