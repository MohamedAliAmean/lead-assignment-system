<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Lead;
use Illuminate\Notifications\Notification;

/**
 * Deliberately NOT queued: the database notification is written inside the
 * escalation transaction, so "escalated" and "notified" commit together
 * (design §9.3).
 */
final class LeadEscalatedNotification extends Notification
{
    public function __construct(private readonly Lead $lead) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'lead_id' => $this->lead->getKey(),
            'lead_name' => $this->lead->name,
            'assigned_to' => $this->lead->assigned_to,
            'created_at' => $this->lead->created_at->toIso8601String(),
            'message' => sprintf('Lead "%s" has been in New status for over %d minutes.', $this->lead->name, (int) config('leads.escalation_delay_minutes')),
        ];
    }
}
