<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Data\BulkAssignData;
use Illuminate\Foundation\Http\FormRequest;

final class BulkAssignLeadsRequest extends FormRequest
{
    /**
     * Shape validation only; authorization, visibility and capacity are
     * business rules enforced by BulkAssignLeads.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lead_ids' => ['required', 'array', 'min:1', 'max:'.(int) config('leads.bulk_assign_max')],
            'lead_ids.*' => ['integer', 'min:1', 'distinct'],
            'assignee_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function toDto(): BulkAssignData
    {
        return new BulkAssignData($this->validated('lead_ids'), (int) $this->validated('assignee_id'));
    }
}
