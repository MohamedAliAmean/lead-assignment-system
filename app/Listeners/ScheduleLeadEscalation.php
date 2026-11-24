<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\LeadCreated;
use App\Jobs\EscalateStaleLead;
use App\Support\LeadSettings;

/**
 * Infrastructure: turns the LeadCreated domain event into a delayed queue job.
 *
 * Keeping this here (not in CreateLead) means the use case only announces what
 * happened and knows nothing about queues. The event dispatches after commit,
 * so the job is only scheduled for leads that really exist. Runs synchronously
 * (not queued) so the 30-minute delay starts right away.
 */
final readonly class ScheduleLeadEscalation
{
    public function __construct(private LeadSettings $settings) {}

    public function handle(LeadCreated $event): void
    {
        EscalateStaleLead::dispatch($event->leadId)
            ->delay(now()->addMinutes($this->settings->escalationDelayMinutes));
    }
}
