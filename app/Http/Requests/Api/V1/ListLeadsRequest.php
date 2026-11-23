<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListLeadsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.mine' => ['sometimes', 'boolean'],
            'filter.unassigned' => ['sometimes', 'boolean'],
            'filter.overdue' => ['sometimes', 'boolean'],
            'filter.priority' => ['sometimes', Rule::in(['high', ...LeadPriority::values()])],
            'filter.status' => ['sometimes', Rule::enum(LeadStatus::class)],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ];
    }

    public function flag(string $name): bool
    {
        return $this->boolean("filter.{$name}");
    }
}
