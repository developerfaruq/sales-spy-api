<?php

namespace App\Http\Requests\User;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateNotificationPreferencesRequest extends FormRequest
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
            'email_on_export_complete' => ['sometimes', 'boolean'],
            'email_on_billing'         => ['sometimes', 'boolean'],
            'email_on_new_features'    => ['sometimes', 'boolean'],
            'email_on_security_alerts' => ['sometimes', 'boolean'],
            'inapp_on_export_complete' => ['sometimes', 'boolean'],
            'inapp_on_low_credits'     => ['sometimes', 'boolean'],
            'inapp_on_scan_complete'   => ['sometimes', 'boolean'],
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
