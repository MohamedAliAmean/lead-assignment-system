<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Lead;
use Filament\Actions\Action;
use Filament\Notifications\Notification as PanelNotification;
use Illuminate\Notifications\Notification;

/**
 * Deliberately NOT queued: the database notification is written inside the
 * escalation transaction, so "escalated" and "notified" commit together
 * (design §9.3).
 *
 * The payload uses the admin panel's notification format, so it appears in the
 * bell (frontend spec FA8), plus plain domain keys for API consumers.
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
        $message = sprintf(
            'Lead "%s" has been in New status for over %d minutes.',
            $this->lead->name,
            (int) config('leads.escalation_delay_minutes'),
        );

        $panelPayload = PanelNotification::make()
            ->title('Lead escalated')
            ->body($message)
            ->warning()
            ->icon('heroicon-o-bell-alert')
            ->actions([
                Action::make('open')
                    ->label('Open lead')
                    ->button()
                    ->url(route('filament.admin.resources.leads.view', ['record' => $this->lead->getKey()]))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();

        return [
            ...$panelPayload,
            'lead_id' => $this->lead->getKey(),
            'lead_name' => $this->lead->name,
            'assigned_to' => $this->lead->assigned_to,
            'created_at' => $this->lead->created_at->toIso8601String(),
            'message' => $message,
        ];
    }
}
