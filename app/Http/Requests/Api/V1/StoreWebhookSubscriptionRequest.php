<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\WebhookEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreWebhookSubscriptionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::enum(WebhookEvent::class)],
            'secret' => ['sometimes', 'nullable', 'string', 'min:16', 'max:255'],
        ];
    }

    /**
     * @return list<WebhookEvent>
     */
    public function events(): array
    {
        return array_map(static fn (string $event): WebhookEvent => WebhookEvent::from($event), $this->validated('events'));
    }
}
