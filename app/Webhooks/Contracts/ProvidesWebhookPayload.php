<?php

declare(strict_types=1);

namespace App\Webhooks\Contracts;

use App\Enums\WebhookEvent;

/**
 * Implemented by domain events that are published to outbound webhooks.
 * The webhook listener depends only on this interface (DIP), so a new event
 * type is a new class (OCP).
 */
interface ProvidesWebhookPayload
{
    public function webhookEvent(): WebhookEvent;

    /**
     * Stable id of this occurrence; identical across listener retries, so it
     * de-duplicates deliveries.
     */
    public function eventId(): string;

    /**
     * @return array<string, mixed>
     */
    public function webhookPayload(): array;
}
