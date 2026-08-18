<?php

namespace App\Http\Requests\Admin;

use App\Models\Setting;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only existing keys can be written.
     *
     * Settings are read with `Setting::get('known_key')` throughout the app, so an
     * arbitrary new key would be inert while looking like it took effect. Adding a
     * genuinely new setting is a code change plus a seeder entry.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.key' => ['required', 'string', Rule::exists('settings', 'key')],
            'settings.*.value' => ['present'],
        ];
    }

    public function messages(): array
    {
        return [
            'settings.*.key.exists' => 'Unknown setting key. Settings must already exist to be updated.',
        ];
    }

    /**
     * Validate each value against the type recorded on its row, so a credit cost
     * cannot be set to a string and a boolean cannot be set to arbitrary text.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('settings', []) as $index => $row) {
                $key = $row['key'] ?? null;
                if (! is_string($key)) {
                    continue;
                }

                $type = Setting::where('key', $key)->value('type');
                $value = $row['value'] ?? null;

                $invalid = match ($type) {
                    'integer' => ! is_int($value) && ! (is_string($value) && ctype_digit(ltrim($value, '-'))),
                    'boolean' => ! is_bool($value) && ! in_array($value, ['true', 'false', '0', '1', 0, 1], true),
                    'json' => ! is_array($value),
                    default => ! is_scalar($value),
                };

                if ($invalid) {
                    $validator->errors()->add(
                        "settings.{$index}.value",
                        "The value for {$key} must be of type {$type}."
                    );
                }
            }
        });
    }

    public function bodyParameters(): array
    {
        return [
            'settings' => [
                'description' => 'Key/value pairs to update. Each key must already exist.',
                'example' => [
                    ['key' => 'crypto_wallet_address', 'value' => 'TXyz...'],
                    ['key' => 'credit_cost_deep_scan', 'value' => 10],
                ],
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
