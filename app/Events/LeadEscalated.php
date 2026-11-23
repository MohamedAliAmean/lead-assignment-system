<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\WebhookEvent;

final class LeadEscalated extends LeadEvent
{
    public function __construct(int $leadId, public readonly int $escalationId, public readonly ?int $managerId)
    {
        parent::__construct($leadId);
    }

    public function webhookEvent(): WebhookEvent
    {
        return WebhookEvent::LeadEscalated;
    }

    protected function data(): array
    {
        return ['escalation_id' => $this->escalationId, 'manager_id' => $this->managerId];
    }
}
