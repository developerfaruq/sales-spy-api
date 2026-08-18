<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
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
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            // Password::defaults() is registered in AppServiceProvider::boot(),
            // so reset can never accept a password registration would reject.
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'token' => [
                'description' => 'The token from the reset link emailed to the user.',
                'example' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6',
            ],
            'email' => [
                'description' => 'The email address the reset link was issued for.',
                'example' => 'john@example.com',
            ],
            'password' => [
                'description' => 'The new password. Min 8 characters.',
                'example' => 'new-password123',
            ],
            'password_confirmation' => [
                'description' => 'Must match password.',
                'example' => 'new-password123',
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
