<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Shared normalization for query-string filters.
 *
 * Query parameters arrive as strings, and a client can always submit an array
 * instead of a scalar (?country[]=US). Normalization runs before validation, so
 * every helper here must leave non-scalar input untouched and let the
 * validation rules reject it with a 422 rather than casting it and raising a
 * 500 inside prepareForValidation().
 */
trait NormalizesQueryFilters
{
    /**
     * Uppercase scalar values for codes such as ISO country and currency.
     *
     * @param  list<string>  $keys
     */
    protected function normalizeUppercaseFilters(array $keys): void
    {
        foreach ($keys as $key) {
            $value = $this->input($key);

            if ($this->has($key) && is_scalar($value)) {
                $this->merge([$key => strtoupper((string) $value)]);
            }
        }
    }

    /**
     * Coerce "true"/"false"/"1"/"0"/"yes"/"no" into real booleans.
     *
     * Laravel's boolean rule rejects the string "true", so without this a
     * documented ?flag=true request would fail validation. Unparseable scalars
     * become null and are then rejected by the boolean rule.
     *
     * @param  list<string>  $keys
     */
    protected function normalizeBooleanFilters(array $keys): void
    {
        foreach ($keys as $key) {
            $value = $this->input($key);

            if ($this->has($key) && is_scalar($value)) {
                $this->merge([
                    $key => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
                ]);
            }
        }
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422));
    }
}
