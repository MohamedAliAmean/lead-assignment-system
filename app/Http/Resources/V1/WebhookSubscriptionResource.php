<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\WebhookSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WebhookSubscription
 */
final class WebhookSubscriptionResource extends JsonResource
{
    private bool $revealSecret = false;

    /**
     * The secret is shown once, in the creation response only.
     */
    public function withSecret(): self
    {
        $this->revealSecret = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'events' => $this->events,
            'is_active' => $this->is_active,
            'secret' => $this->when($this->revealSecret, fn (): string => $this->secret),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
