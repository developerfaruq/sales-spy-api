<?php

namespace App\Http\Requests\Lead;

use App\Enums\ProductStatus;
use App\Http\Requests\Concerns\NormalizesQueryFilters;
use App\Services\LeadQueryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductIndexRequest extends FormRequest
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
            'vendor' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
            'available' => ['sometimes', 'boolean'],
            'min_price' => ['sometimes', 'numeric', 'min:0'],
            'max_price' => ['sometimes', 'numeric', 'min:0', 'gte:min_price'],
            // Derived from the service map so a validated key always resolves to
            // a real column instead of silently using the default ordering.
            'sort' => ['sometimes', Rule::in(array_keys(LeadQueryService::PRODUCT_SORTS))],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanFilters(['available']);
    }

    public function queryParameters(): array
    {
        return [
            'q' => ['description' => 'Full-text search across title, vendor, and product type.', 'example' => 'running shoe'],
            'vendor' => ['description' => 'Exact product vendor.', 'example' => 'Acme'],
            'type' => ['description' => 'Exact product type.', 'example' => 'Shoes'],
            'status' => ['description' => 'Product status.', 'example' => 'active'],
            'available' => ['description' => 'Filter currently available products.', 'example' => true],
            'min_price' => ['description' => 'Minimum price in major currency units.', 'example' => 10],
            'max_price' => ['description' => 'Maximum price in major currency units.', 'example' => 100],
            'sort' => ['description' => 'Sort field.', 'example' => 'price'],
            'direction' => ['description' => 'Sort direction.', 'example' => 'asc'],
            'per_page' => ['description' => 'Results per page. Maximum 100.', 'example' => 25],
        ];
    }
}
