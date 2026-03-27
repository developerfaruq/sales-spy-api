<?php

namespace App\Http\Requests\User;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
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
            'name'  => ['sometimes', 'string', 'min:2', 'max:100'],
            'email' => [
                'sometimes',
                'email',
                // Unique except for the current user's own email
                Rule::unique('users', 'email')->ignore($this->user()->id),
            ],
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'name'  => ['description' => 'The user\'s display name.', 'example' => 'John Doe'],
            'email' => ['description' => 'A valid unique email address.', 'example' => 'john@example.com'],
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
