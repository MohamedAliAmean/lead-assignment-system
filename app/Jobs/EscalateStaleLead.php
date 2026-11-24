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
 *
 * The unique lock is keyed per ORIGIN. The scheduled copy holds a long lock
 * (it must outlive the 30-minute delay), while sweeper copies use their own
 * short lock. If a delayed job is lost (queue flushed), its lock can no longer
 * silently block the sweeper's replacement, which is exactly the case the
 * sweeper exists for. Found by the end-to-end scenarios.
 */
final class EscalateStaleLead implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    public const string ORIGIN_SCHEDULED = 'scheduled';

    public const string ORIGIN_SWEEPER = 'sweeper';

    /** Sweeper copies: at most one queued per lead per 5 minutes. */
    private const int SWEEPER_LOCK_SECONDS = 300;

    public function __construct(
        public readonly int $leadId,
        public readonly string $origin = self::ORIGIN_SCHEDULED,
    ) {
        $this->onQueue('escalations');
    }

    public function uniqueId(): string
    {
        return "{$this->leadId}:{$this->origin}";
    }

    /**
     * Scheduled copy: the lock must outlive the dispatch delay. Sweeper copy:
     * short, so a stuck lead is retried every few minutes at most.
     */
    public function uniqueFor(): int
    {
        return $this->origin === self::ORIGIN_SWEEPER
            ? self::SWEEPER_LOCK_SECONDS
            : ((int) config('leads.escalation_delay_minutes') + 60) * 60;
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
