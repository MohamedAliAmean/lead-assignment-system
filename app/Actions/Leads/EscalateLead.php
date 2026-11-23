<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\LeadStatus;
use App\Escalation\Contracts\EscalationRecipientResolver;
use App\Events\LeadEscalated;
use App\Models\Lead;
use App\Models\LeadEscalation;
use App\Models\LeadStatusLog;
use App\Notifications\LeadEscalatedNotification;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Notification;

/**
 * Escalates a lead that is still "new" after the delay (R5). Idempotent:
 *  - re-reads the lead under a row lock and does nothing if it changed (R5.2/R5.3);
 *  - the 30 minutes count from when the lead last entered New;
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
            $dueAt = $this->enteredNewAt($lead)->addMinutes((int) config('leads.escalation_delay_minutes'));

            if ($now->lessThan($dueAt)) {
                return false; // not due yet; the sweeper re-checks every minute
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

    /**
     * "Remains in New for 30 minutes" (R5) counts from the moment the lead last
     * entered New, not from creation: a lead moved New -> Contacted -> New
     * restarts its clock. Read from the audit log (index lead_id, changed_at).
     */
    private function enteredNewAt(Lead $lead): CarbonImmutable
    {
        $lastEntered = LeadStatusLog::query()
            ->where('lead_id', $lead->getKey())
            ->where('new_status', LeadStatus::New->value)
            ->max('changed_at');

        return $lastEntered === null
            ? $lead->created_at
            : CarbonImmutable::parse((string) $lastEntered, (string) config('app.timezone'));
    }
}
