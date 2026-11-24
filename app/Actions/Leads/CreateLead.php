<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Assignment\LeadAssigner;
use App\Authorization\ActorContext;
use App\Data\CreateLeadData;
use App\Enums\LeadStatus;
use App\Events\LeadAssigned;
use App\Events\LeadCreated;
use App\Models\Lead;
use App\Models\User;
use App\Support\BusinessClock;
use App\Support\LeadSettings;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Creates a lead and assigns it automatically with Round Robin (R3.1).
 *
 * The use case only announces what happened (LeadCreated / LeadAssigned).
 * Scheduling the 30-minute escalation (R5.1) is an infrastructure concern,
 * handled by the ScheduleLeadEscalation listener after commit.
 *
 * Authorization is the caller's responsibility (policy "create" in the admin
 * panel and API; signature verification for inbound webhooks).
 */
final readonly class CreateLead
{
    public function __construct(
        private ConnectionInterface $db,
        private LeadAssigner $assigner,
        private ActorContext $actors,
        private BusinessClock $clock,
        private LeadSettings $settings,
        private Dispatcher $events,
    ) {}

    /**
     * When an external reference is given and a lead with the same
     * (source, external_ref) already exists, that lead is returned instead
     * ($lead->wasRecentlyCreated === false), see R12.3.
     */
    public function handle(CreateLeadData $data, ?User $actor = null): Lead
    {
        $existing = $this->findExisting($data);

        if ($existing !== null) {
            return $existing;
        }

        try {
            $lead = $this->actors->runAs($actor, fn (): Lead => $this->db->transaction(
                fn (): Lead => $this->createAndAssign($data),
                attempts: 3, // retried automatically on deadlock
            ));
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent identical delivery won the race. Our whole transaction
            // (including the capacity reservation) was rolled back.
            return $this->findExisting($data) ?? throw $e;
        }

        return $lead;
    }

    private function createAndAssign(CreateLeadData $data): Lead
    {
        $now = $this->clock->now();

        // Cursor lock -> ledger reservation happen first, then the insert
        // (fixed lock order, and one write for the lead instead of two).
        $agentId = $this->assigner->claimNextAgent();

        $lead = new Lead([
            'name' => $data->name,
            'phone' => $data->phone,
            'source' => $data->source,
            'external_ref' => $data->externalRef,
            'priority' => $data->priority,
            'status' => LeadStatus::New,
            // A-12: default follow-up deadline = escalation deadline.
            'next_follow_up_at' => $data->nextFollowUpAt ?? $now->addMinutes($this->settings->escalationDelayMinutes),
        ]);

        $lead->forceFill([
            'assigned_to' => $agentId,
            'assigned_at' => $agentId !== null ? $now : null,
        ])->save();

        // Domain events; delivered only after the transaction commits.
        $this->events->dispatch(new LeadCreated($lead->getKey(), $data->source->value, $data->priority->value));

        if ($agentId !== null) {
            $this->events->dispatch(new LeadAssigned($lead->getKey(), $agentId, null, 'round_robin'));
        }

        return $lead;
    }

    private function findExisting(CreateLeadData $data): ?Lead
    {
        if ($data->externalRef === null) {
            return null;
        }

        return Lead::query()
            ->where('source', $data->source->value)
            ->where('external_ref', $data->externalRef)
            ->first();
    }
}
