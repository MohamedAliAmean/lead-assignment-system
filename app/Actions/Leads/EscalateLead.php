<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\LeadStatus;
use App\Escalation\Contracts\EscalationRecipientResolver;
use App\Events\LeadEscalated;
use App\Models\Lead;
use App\Models\LeadEscalation;
use App\Notifications\LeadEscalatedNotification;
use App\Support\BusinessClock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Notification;

/**
 * Escalates a lead that is still "new" after the delay (R5). Idempotent:
 *  - re-reads the lead under a row lock and does nothing if it changed (R5.2/R5.3);
 *  - UNIQUE(lead_id) on lead_escalations stops duplicates before any side
 *    effect (R5.5);
 *  - the escalation row and the notification share one transaction, so a
 *    failure leaves nothing behind and a retry starts clean (R5.6);
 *  - never modifies the lead itself (R5.7).
 */
final readonly class EscalateLead
{
    public function __construct(
        private ConnectionInterface $db,
        private EscalationRecipientResolver $resolver,
        private BusinessClock $clock,
    ) {}

    /**
     * @return bool true if this call performed the escalation
     */
    public function handle(int $leadId): bool
    {
        return $this->db->transaction(function () use ($leadId): bool {
            /** @var Lead|null $lead */
            $lead = Lead::query()->lockForUpdate()->find($leadId);

            if ($lead === null || $lead->status !== LeadStatus::New) {
                return false;
            }

            $now = $this->clock->now();
            $dueAt = $lead->created_at->addMinutes((int) config('leads.escalation_delay_minutes'));

            if ($now->lessThan($dueAt)) {
                return false; // ran early; the sweeper will pick it up when due
            }

            $target = $this->resolver->resolve($lead);

            $inserted = LeadEscalation::query()->insertOrIgnore([
                'lead_id' => $lead->getKey(),
                'manager_id' => $target->manager?->getKey(),
                'escalated_at' => $now,
            ]);

            if ($inserted === 0) {
                return false; // already escalated: no second side effect
            }

            Notification::sendNow($target->recipients, new LeadEscalatedNotification($lead));

            $escalationId = (int) LeadEscalation::query()->where('lead_id', $lead->getKey())->value('id');
            LeadEscalated::dispatch($lead->getKey(), $escalationId, $target->manager?->getKey());

            return true;
        }, attempts: 3);
    }
}
