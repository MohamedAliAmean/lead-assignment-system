<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Lead
 */
final class LeadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'source' => $this->source->value,
            'external_ref' => $this->external_ref,
            'priority' => $this->priority->value,
            'priority_label' => $this->priority->label(),
            'status' => $this->status->value,
            'assigned_to' => $this->assigned_to,
            'assigned_user' => UserSummaryResource::make($this->whenLoaded('assignedUser')),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'next_follow_up_at' => $this->next_follow_up_at?->toIso8601String(),
            'is_overdue' => $this->isOverdue(now()->toImmutable()),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
