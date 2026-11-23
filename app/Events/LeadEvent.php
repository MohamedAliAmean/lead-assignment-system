<?php

declare(strict_types=1);

namespace App\Events;

use App\Webhooks\Contracts\ProvidesWebhookPayload;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * Base for lead domain events. Dispatched only after the surrounding
 * transaction commits, so listeners never react to rolled-back changes.
 */
abstract class LeadEvent implements ProvidesWebhookPayload, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public readonly string $eventId;

    public readonly string $occurredAt;

    public function __construct(public readonly int $leadId)
    {
        $this->eventId = (string) Str::uuid();
        $this->occurredAt = now()->toIso8601String();
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function webhookPayload(): array
    {
        return [
            'event_id' => $this->eventId,
            'event' => $this->webhookEvent()->value,
            'occurred_at' => $this->occurredAt,
            'data' => ['lead_id' => $this->leadId, ...$this->data()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function data(): array;
}
