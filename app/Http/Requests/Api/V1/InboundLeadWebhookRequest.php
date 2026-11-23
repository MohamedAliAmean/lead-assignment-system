<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Data\CreateLeadData;
use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class InboundLeadWebhookRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'external_id' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[\d\s\-()]{6,32}$/'],
            'priority' => ['sometimes', 'integer', Rule::enum(LeadPriority::class)],
        ];
    }

    /**
     * The source comes from the verified X-Webhook-Source header, never from the body.
     */
    public function toDto(): CreateLeadData
    {
        return new CreateLeadData(
            name: (string) $this->validated('name'),
            phone: (string) $this->validated('phone'),
            source: LeadSource::from((string) $this->header('X-Webhook-Source')),
            priority: LeadPriority::from((int) $this->validated('priority', LeadPriority::Medium->value)),
            externalRef: (string) $this->validated('external_id'),
        );
    }
}
