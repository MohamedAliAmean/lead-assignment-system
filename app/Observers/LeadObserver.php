<?php

declare(strict_types=1);

namespace App\Observers;

use App\Authorization\Contracts\CurrentActor;
use App\Models\Lead;
use App\Models\LeadStatusLog;
use App\Support\BusinessClock;

/**
 * Status audit (R4). An observer is the one hook every Eloquent save passes
 * through (admin panel, API, jobs, tinker), so no write path can forget to log.
 * It runs on the same connection inside the caller's transaction, so the log
 * row commits or rolls back with the change (R4.2).
 *
 * Status must only change via Eloquent saves (ChangeLeadStatus); query-builder
 * mass updates skip model events and are not used for status.
 */
final readonly class LeadObserver
{
    public function __construct(
        private CurrentActor $actor,
        private BusinessClock $clock,
    ) {}

    public function created(Lead $lead): void
    {
        $this->log($lead, null, $lead->getRawOriginal('status') ?? $lead->status->value);
    }

    public function updated(Lead $lead): void
    {
        if (! $lead->wasChanged('status')) {
            return; // R4.3: no entry when the status did not change
        }

        $this->log($lead, $lead->getRawOriginal('status'), $lead->status->value);
    }

    private function log(Lead $lead, ?string $old, string $new): void
    {
        LeadStatusLog::query()->create([
            'lead_id' => $lead->getKey(),
            'user_id' => $this->actor->id(),
            'old_status' => $old,
            'new_status' => $new,
            'changed_at' => $this->clock->now()->format('Y-m-d H:i:s.v'),
        ]);
    }
}
