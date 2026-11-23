<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\LeadStatusLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LeadStatusLog
 */
final class LeadStatusLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'old_status' => $this->old_status?->value,
            'new_status' => $this->new_status->value,
            'changed_by' => UserSummaryResource::make($this->whenLoaded('user')),
            'changed_at' => $this->changed_at->toIso8601String(),
        ];
    }
}
