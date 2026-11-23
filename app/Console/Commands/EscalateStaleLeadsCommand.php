<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\EscalateStaleLead;
use App\Models\Lead;
use App\Support\BusinessClock;
use Illuminate\Console\Command;

/**
 * Safety net for the delayed escalation job (design §9.3). Delayed jobs can be
 * lost (queue flushed, tries exhausted) and some drivers cap delays (Amazon SQS
 * allows at most 15 minutes). This finds due, un-escalated "new" leads and
 * dispatches the idempotent job for them.
 */
final class EscalateStaleLeadsCommand extends Command
{
    protected $signature = 'leads:escalate-stale';

    protected $description = 'Dispatch escalation jobs for leads that stayed in New status past the escalation delay';

    public function handle(BusinessClock $clock): int
    {
        $dueBefore = $clock->now()->subMinutes((int) config('leads.escalation_delay_minutes'));
        $dispatched = 0;

        Lead::query()
            ->pendingEscalation($dueBefore)
            ->select('leads.id')
            ->chunkById(500, function ($leads) use (&$dispatched): void {
                foreach ($leads as $lead) {
                    EscalateStaleLead::dispatch($lead->getKey());
                    $dispatched++;
                }
            }, 'leads.id', 'id');

        $this->components->info("Dispatched {$dispatched} escalation job(s).");

        return self::SUCCESS;
    }
}
