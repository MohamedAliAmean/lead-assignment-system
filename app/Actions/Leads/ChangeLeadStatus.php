<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Authorization\ActorContext;
use App\Enums\LeadStatus;
use App\Events\LeadStatusChanged;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\ConnectionInterface;

/**
 * The single code path for status changes (R4). The audit row is written by
 * LeadObserver inside this transaction.
 */
final readonly class ChangeLeadStatus
{
    public function __construct(
        private ConnectionInterface $db,
        private Gate $gate,
        private ActorContext $actors,
    ) {}

    public function handle(Lead $lead, LeadStatus $to, User $actor): Lead
    {
        $this->gate->forUser($actor)->authorize('changeStatus', $lead);

        return $this->actors->runAs($actor, fn (): Lead => $this->db->transaction(function () use ($lead, $to, $actor): Lead {
            // Row lock: serializes with EscalateLead, which locks the same row (design §9.3).
            /** @var Lead $locked */
            $locked = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());

            if ($locked->status === $to) {
                return $locked; // R4.3: unchanged status, no audit entry
            }

            $old = $locked->status;
            $locked->status = $to;
            $locked->save();

            LeadStatusChanged::dispatch($locked->getKey(), $old->value, $to->value, $actor->getKey());

            return $locked;
        }, attempts: 3));
    }
}
