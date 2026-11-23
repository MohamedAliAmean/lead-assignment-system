<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\WebhookEvent;

final class LeadAssigned extends LeadEvent
{
    public function __construct(
        int $leadId,
        public readonly int $assigneeId,
        public readonly ?int $previousAssigneeId,
        public readonly string $method,
    ) {
        parent::__construct($leadId);
    }

    public function webhookEvent(): WebhookEvent
    {
        return WebhookEvent::LeadAssigned;
    }

    protected function data(): array
    {
        return [
            'assignee_id' => $this->assigneeId,
            'previous_assignee_id' => $this->previousAssigneeId,
            'method' => $this->method,
        ];
    }
}
