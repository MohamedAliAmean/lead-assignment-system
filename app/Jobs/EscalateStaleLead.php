<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Leads\EscalateLead;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delayed follow-up escalation (R5). Carries only the lead id; all state is
 * re-read when it runs. Safe to run any number of times (see EscalateLead).
 *
 * ShouldBeUnique only avoids enqueueing obvious duplicates; the real guarantee
 * is the UNIQUE constraint used by EscalateLead.
 */
final class EscalateStaleLead implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(public readonly int $leadId)
    {
        $this->onQueue('escalations');
    }

    public function uniqueId(): string
    {
        return (string) $this->leadId;
    }

    /**
     * The lock must outlive the dispatch delay, otherwise the sweeper could
     * enqueue a second copy while the first is still waiting.
     */
    public function uniqueFor(): int
    {
        return ((int) config('leads.escalation_delay_minutes') + 60) * 60;
    }

    public function handle(EscalateLead $escalate): void
    {
        $escalate->handle($this->leadId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Lead escalation failed; the sweeper will retry.', [
            'lead_id' => $this->leadId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
