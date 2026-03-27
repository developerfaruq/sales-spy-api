<?php

namespace App\Http\Requests\User;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class ChangePasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            //
            'current_password' => ['required', 'string'],
            'new_password'     => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'new_password.confirmed' => 'New passwords do not match.',
            'new_password.min'       => 'New password must be at least 8 characters.',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'current_password'          => ['description' => 'Your current password.', 'example' => 'oldpassword123'],
            'new_password'              => ['description' => 'Your new password. Min 8 characters.', 'example' => 'newpassword123'],
            'new_password_confirmation' => ['description' => 'Must match new_password.', 'example' => 'newpassword123'],
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
