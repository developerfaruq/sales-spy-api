<?php

namespace App\Http\Requests\Lead;

use App\Enums\WebsiteStatus;
use App\Http\Requests\Concerns\NormalizesQueryFilters;
use App\Services\LeadQueryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WebsiteIndexRequest extends FormRequest
{
    use NormalizesQueryFilters;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:255'],
            'country' => ['sometimes', 'string', 'size:2', 'alpha'],
            'platform' => ['sometimes', 'string', 'max:64'],
            'cms' => ['sometimes', 'string', 'max:64'],
            'niche' => ['sometimes', 'string', 'max:255'],
            'technology' => ['sometimes', 'string', 'max:100'],
            'is_ecommerce' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::enum(WebsiteStatus::class)],
            // Derived from the service map so a validated key always resolves to
            // a real column instead of silently using the default ordering.
            'sort' => ['sometimes', Rule::in(array_keys(LeadQueryService::WEBSITE_SORTS))],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeUppercaseFilters(['country']);
        $this->normalizeBooleanFilters(['is_ecommerce']);
    }

    public function queryParameters(): array
    {
        return [
            'q' => ['description' => 'Full-text search across domain, name, and description.', 'example' => 'fashion'],
            'country' => ['description' => 'ISO 3166-1 alpha-2 country code.', 'example' => 'US'],
            'platform' => ['description' => 'Detected website or commerce platform.', 'example' => 'shopify'],
            'cms' => ['description' => 'Detected content management system.', 'example' => 'wordpress'],
            'niche' => ['description' => 'Exact niche classification.', 'example' => 'Fashion'],
            'technology' => ['description' => 'Technology that must exist in the technology JSON array.', 'example' => 'Cloudflare'],
            'is_ecommerce' => ['description' => 'Filter e-commerce or non-commerce websites.', 'example' => true],
            'status' => ['description' => 'Website status.', 'example' => 'active'],
            'sort' => ['description' => 'Sort field.', 'example' => 'last_seen_at'],
            'direction' => ['description' => 'Sort direction.', 'example' => 'desc'],
            'per_page' => ['description' => 'Results per page. Maximum 100.', 'example' => 25],
        ];
    }
}
