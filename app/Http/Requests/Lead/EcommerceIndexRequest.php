<?php

namespace App\Http\Requests\Lead;

use App\Enums\CommercePlatform;
use App\Services\LeadQueryService;
use Illuminate\Validation\Rule;

class EcommerceIndexRequest extends WebsiteIndexRequest
{
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:255'],
            'country' => ['sometimes', 'string', 'size:2', 'alpha'],
            'platform' => ['sometimes', Rule::enum(CommercePlatform::class)],
            'category' => ['sometimes', 'string', 'max:255'],
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'min_products' => ['sometimes', 'integer', 'min:0'],
            'max_products' => ['sometimes', 'integer', 'min:0', 'gte:min_products'],
            'min_price' => ['sometimes', 'numeric', 'min:0'],
            'max_price' => ['sometimes', 'numeric', 'min:0', 'gte:min_price'],
            // Derived from the service map so a validated key always resolves to
            // a real column instead of silently using the default ordering.
            'sort' => ['sometimes', Rule::in(array_keys(LeadQueryService::ECOMMERCE_SORTS))],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->normalizeUppercaseFilters(['currency']);
    }

    public function queryParameters(): array
    {
        return [
            'q' => ['description' => 'Full-text search across store name, category, and platform.', 'example' => 'fashion'],
            'country' => ['description' => 'ISO 3166-1 alpha-2 country code.', 'example' => 'US'],
            'platform' => ['description' => 'Commerce platform.', 'example' => 'shopify'],
            'category' => ['description' => 'Exact store category.', 'example' => 'Fashion'],
            'currency' => ['description' => 'ISO 4217 currency code.', 'example' => 'USD'],
            'min_products' => ['description' => 'Minimum product count.', 'example' => 10],
            'max_products' => ['description' => 'Maximum product count.', 'example' => 5000],
            'min_price' => ['description' => 'Minimum average product price in major currency units.', 'example' => 10],
            'max_price' => ['description' => 'Maximum average product price in major currency units.', 'example' => 100],
            'sort' => ['description' => 'Sort field.', 'example' => 'product_count'],
            'direction' => ['description' => 'Sort direction.', 'example' => 'desc'],
            'per_page' => ['description' => 'Results per page. Maximum 100.', 'example' => 25],
        ];
    }
}
