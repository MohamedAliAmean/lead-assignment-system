<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Assignment\CapacityLedger;
use App\Authorization\AssigneeEligibility;
use App\Data\BulkAssignData;
use App\Events\LeadAssigned;
use App\Exceptions\Domain\AgentCapacityExceeded;
use App\Exceptions\Domain\AssigneeNotEligible;
use App\Exceptions\Domain\BulkAssignLimitExceeded;
use App\Exceptions\Domain\LeadsNotAccessible;
use App\Models\Lead;
use App\Models\User;
use App\Support\BusinessClock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\ConnectionInterface;

/**
 * Manually assigns several leads to one agent (R7.4).
 *
 * All-or-nothing (A-7): if the actor may not manage every selected lead, may
 * not assign to the target, or the target lacks capacity for all moved leads,
 * nothing is assigned. Manual assignment counts against capacity (A-8).
 *
 * Lock order: lead rows (ascending id) -> target's ledger row. The automatic
 * path locks cursor -> ledger -> a NEW lead nobody else can see, so the two
 * paths cannot form a lock cycle (design §7.4).
 */
final readonly class BulkAssignLeads
{
    public function __construct(
        private ConnectionInterface $db,
        private AssigneeEligibility $eligibility,
        private CapacityLedger $ledger,
        private BusinessClock $clock,
    ) {}

    /**
     * @return int number of leads that changed assignee
     */
    public function handle(BulkAssignData $data, User $actor): int
    {
        if (! $this->eligibility->canAssign($actor)) {
            throw new AuthorizationException('You are not allowed to assign leads.');
        }

        $max = (int) config('leads.bulk_assign_max');

        if (count($data->leadIds) > $max) {
            throw new BulkAssignLimitExceeded(count($data->leadIds), $max);
        }

        $target = User::query()->find($data->assigneeId);

        if ($target === null || ! $this->eligibility->canAssignTo($actor, $target)) {
            throw new AssigneeNotEligible($data->assigneeId);
        }

        if ($data->leadIds === []) {
            return 0;
        }

        return $this->db->transaction(function () use ($data, $actor, $target): int {
            $leads = Lead::query()
                ->visibleTo($actor)
                ->whereKey($data->leadIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'assigned_to']);

            if ($leads->count() !== count($data->leadIds)) {
                throw new LeadsNotAccessible(array_values(array_diff($data->leadIds, $leads->modelKeys())));
            }

            $toMove = $leads->filter(static fn (Lead $lead): bool => $lead->assigned_to !== $target->getKey());

            if ($toMove->isEmpty()) {
                return 0;
            }

            $day = $this->clock->today();

            if (! $this->ledger->tryReserve($target->getKey(), $day, $toMove->count())) {
                throw new AgentCapacityExceeded(
                    $target->getKey(),
                    $toMove->count(),
                    $this->ledger->remaining($target, $day),
                );
            }

            // Assignment columns only; status is untouched, so skipping model
            // events here does not bypass the status audit.
            Lead::query()->whereKey($toMove->modelKeys())->update([
                'assigned_to' => $target->getKey(),
                'assigned_at' => $this->clock->now(),
                'updated_at' => $this->clock->now(),
            ]);

            foreach ($toMove as $lead) {
                LeadAssigned::dispatch($lead->getKey(), $target->getKey(), $lead->assigned_to, 'manual');
            }

            return $toMove->count();
        }, attempts: 3);
    }
}
