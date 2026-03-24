<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // allow anyone to attempt registration
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required',  'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
    public function messages(): array
    {
        return [
            'email.unique'          => 'An account with this email already exists.',
            'password.confirmed'    => 'Passwords do not match.',
            'password.min'          => 'Password must be at least 8 characters.',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'name' => [
                'description' => 'The user\'s full name.',
                'example'     => 'John Doe',
            ],
            'email' => [
                'description' => 'A valid, unique email address.',
                'example'     => 'john@example.com',
            ],
            'password' => [
                'description' => 'Minimum 8 characters.',
                'example'     => 'password123',
            ],
            'password_confirmation' => [
                'description' => 'Must match the password field.',
                'example'     => 'password123',
            ],
        ];
    }
    // This overrides Laravel's default validation error response
    // to use YOUR consistent response format instead of Laravel's default
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
