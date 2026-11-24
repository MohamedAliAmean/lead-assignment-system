<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Data\UpdateLeadData;
use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partial update (PATCH): only the sent fields change.
 */
final class UpdateLeadRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'phone' => ['sometimes', 'string', 'max:32', 'regex:/^\+?[\d\s\-()]{6,32}$/'],
            'source' => ['sometimes', Rule::enum(LeadSource::class)],
            'priority' => ['sometimes', 'integer', Rule::enum(LeadPriority::class)],
            'next_follow_up_at' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::enum(LeadStatus::class)],
        ];
    }

    public function toDto(): UpdateLeadData
    {
        return UpdateLeadData::fromArray($this->validated());
    }
}
