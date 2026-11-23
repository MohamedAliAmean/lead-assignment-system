<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\WebhookEvent;

final class LeadCreated extends LeadEvent
{
    public function __construct(int $leadId, public readonly string $source, public readonly int $priority)
    {
        parent::__construct($leadId);
    }

    public function webhookEvent(): WebhookEvent
    {
        return WebhookEvent::LeadCreated;
    }

    protected function data(): array
    {
        return ['source' => $this->source, 'priority' => $this->priority];
    }
}
