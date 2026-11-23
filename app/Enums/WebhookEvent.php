<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum WebhookEvent: string
{
    use HasValues;

    case LeadCreated = 'lead.created';
    case LeadAssigned = 'lead.assigned';
    case LeadStatusChanged = 'lead.status_changed';
    case LeadEscalated = 'lead.escalated';

    public function label(): string
    {
        return $this->value;
    }
}
