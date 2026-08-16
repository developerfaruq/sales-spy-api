<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class EnqueueDomainsRequest extends FormRequest
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
            // Capped so one request cannot write an unbounded batch. Larger
            // volumes belong in the worker's Common Crawl discovery command.
            'domains' => ['required', 'array', 'min:1', 'max:500'],
            'domains.*' => ['required', 'string', 'max:253'],
            'source' => ['sometimes', 'string', 'max:64', 'alpha_dash'],
        ];
    }

    public function messages(): array
    {
        return [
            'domains.max' => 'A maximum of 500 domains can be queued per request.',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'domains' => [
                'description' => 'Domains or URLs to queue for crawling. Normalized before insert.',
                'example' => ['example-store.com', 'https://www.another-store.com/collections/all'],
            ],
            'source' => [
                'description' => 'Provenance label stored on each row.',
                'example' => 'admin',
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
