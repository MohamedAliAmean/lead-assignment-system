<?php

declare(strict_types=1);

namespace App\Assignment;

use App\Assignment\Contracts\AssignmentStrategy;
use App\Models\AssignmentCursor;
use App\Support\BusinessClock;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * Picks the next agent for a new lead and reserves their capacity
 * (design §6.2 / §6.4).
 *
 * Lock order (always the same, to avoid deadlocks):
 *   assignment cursor row -> agent ledger row -> lead row
 */
final readonly class LeadAssigner
{
    private const int CANDIDATE_BATCH = 50;

    public function __construct(
        private ConnectionInterface $db,
        private AssignmentStrategy $strategy,
        private CapacityLedger $ledger,
        private BusinessClock $clock,
    ) {}

    /**
     * Claim the next eligible agent: reserve one capacity slot for today and
     * move the Round Robin cursor to them. Returns NULL when nobody is eligible
     * (assumption A-4).
     *
     * Must run inside the caller's transaction, so that the reservation, the
     * cursor move and the lead insert commit or roll back together.
     */
    public function claimNextAgent(): ?int
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('LeadAssigner::claimNextAgent() must run inside a database transaction.');
        }

        // 1. Serialize the rotation: concurrent creations queue here, and each
        //    one reads the cursor as moved by the previous commit.
        /** @var AssignmentCursor $cursor */
        $cursor = AssignmentCursor::query()
            ->where('pool', AssignmentCursor::GLOBAL_POOL)
            ->lockForUpdate()
            ->firstOrFail();

        $day = $this->clock->today();

        // 2. Try candidates in Round Robin order. A failed reservation means
        //    the agent filled up or was deactivated since the read (for
        //    example by a concurrent bulk assignment); move on to the next.
        foreach ($this->strategy->candidates($cursor->last_user_id, $day, self::CANDIDATE_BATCH) as $agentId) {
            if ($this->ledger->tryReserve($agentId, $day)) {
                $cursor->forceFill(['last_user_id' => $agentId])->save();

                return $agentId;
            }
        }

        return null;
    }
}
