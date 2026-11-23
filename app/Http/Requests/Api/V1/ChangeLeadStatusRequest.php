<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\LeadStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeLeadStatusRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(LeadStatus::class)],
        ];
    }

    public function status(): LeadStatus
    {
        return LeadStatus::from((string) $this->validated('status'));
    }
}
