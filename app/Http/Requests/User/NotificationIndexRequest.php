<?php

namespace App\Http\Requests\User;

use App\Enums\NotificationType;
use App\Http\Requests\Concerns\NormalizesQueryFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationIndexRequest extends FormRequest
{
    use NormalizesQueryFilters;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unread' => ['sometimes', 'boolean'],
            'type' => ['sometimes', Rule::enum(NotificationType::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function queryParameters(): array
    {
        return [
            'unread' => ['description' => 'Return only unread notifications.', 'example' => true],
            'type' => ['description' => 'Filter by notification type.', 'example' => 'scan_completed'],
            'per_page' => ['description' => 'Results per page. Maximum 100.', 'example' => 25],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanFilters(['unread']);
    }
}
