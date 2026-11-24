<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Escalation\Contracts\EscalationNotifier;
use App\Escalation\EscalationTarget;
use App\Models\Lead;
use Illuminate\Contracts\Notifications\Dispatcher;

/**
 * Infrastructure adapter for the EscalationNotifier port: stores a database
 * notification (shown in the admin panel bell) for every recipient.
 *
 * sendNow() keeps it synchronous, so the notification is written inside the
 * escalation transaction.
 */
final readonly class DatabaseEscalationNotifier implements EscalationNotifier
{
    public function __construct(private Dispatcher $notifications) {}

    public function notify(Lead $lead, EscalationTarget $target): void
    {
        $this->notifications->sendNow($target->recipients, new LeadEscalatedNotification($lead));
    }
}
