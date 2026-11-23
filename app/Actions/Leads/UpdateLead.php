<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Authorization\ActorContext;
use App\Data\UpdateLeadData;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\ConnectionInterface;

/**
 * Edits contact details and the follow-up date. A status change is delegated
 * to ChangeLeadStatus, so there is exactly one status code path.
 */
final readonly class UpdateLead
{
    public function __construct(
        private ConnectionInterface $db,
        private Gate $gate,
        private ActorContext $actors,
        private ChangeLeadStatus $changeStatus,
    ) {}

    public function handle(Lead $lead, UpdateLeadData $data, User $actor): Lead
    {
        $this->gate->forUser($actor)->authorize('update', $lead);

        return $this->actors->runAs($actor, fn (): Lead => $this->db->transaction(function () use ($lead, $data, $actor): Lead {
            /** @var Lead $locked */
            $locked = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());

            $locked->fill([
                'name' => $data->name,
                'phone' => $data->phone,
                'source' => $data->source,
                'priority' => $data->priority,
                'next_follow_up_at' => $data->nextFollowUpAt,
            ])->save();

            if ($data->status !== null && $data->status !== $locked->status) {
                return $this->changeStatus->handle($locked, $data->status, $actor);
            }

            return $locked;
        }, attempts: 3));
    }
}
