<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Data\CreateLeadData;
use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreLeadRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[\d\s\-()]{6,32}$/'],
            'source' => ['required', Rule::enum(LeadSource::class)],
            'priority' => ['sometimes', 'integer', Rule::enum(LeadPriority::class)],
            'next_follow_up_at' => ['sometimes', 'nullable', 'date'],
            'external_ref' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function toDto(): CreateLeadData
    {
        return CreateLeadData::fromArray($this->validated());
    }
}
