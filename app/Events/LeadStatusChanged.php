<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\WebhookEvent;

final class LeadStatusChanged extends LeadEvent
{
    public function __construct(
        int $leadId,
        public readonly string $oldStatus,
        public readonly string $newStatus,
        public readonly ?int $actorId,
    ) {
        parent::__construct($leadId);
    }

    public function webhookEvent(): WebhookEvent
    {
        return WebhookEvent::LeadStatusChanged;
    }

    protected function data(): array
    {
        return ['old_status' => $this->oldStatus, 'new_status' => $this->newStatus, 'changed_by' => $this->actorId];
    }
}
