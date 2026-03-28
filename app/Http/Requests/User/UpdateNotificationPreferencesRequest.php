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
    public function bodyParameters(): array
    {
        return [
            'email_on_export_complete' => ['description' => 'Email when an export finishes.', 'example' => true],
            'email_on_billing'         => ['description' => 'Email for billing events.', 'example' => false],
            'email_on_new_features'    => ['description' => 'Email about new features.', 'example' => true],
            'email_on_security_alerts' => ['description' => 'Email for security alerts.', 'example' => true],
            'inapp_on_export_complete' => ['description' => 'In-app alert when export finishes.', 'example' => true],
            'inapp_on_low_credits'     => ['description' => 'In-app alert when credits are low.', 'example' => true],
            'inapp_on_scan_complete'   => ['description' => 'In-app alert when a scan finishes.', 'example' => false],
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
