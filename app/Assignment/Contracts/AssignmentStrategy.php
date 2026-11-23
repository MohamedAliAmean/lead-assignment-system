<?php

declare(strict_types=1);

namespace App\Assignment\Contracts;

/**
 * Decides the order in which eligible agents are tried for the next lead.
 * Implementations only read; reserving capacity and moving the cursor is the
 * job of LeadAssigner (SRP, design §6.1).
 */
interface AssignmentStrategy
{
    /**
     * Ids of eligible agents (active, under capacity for $day), in the order
     * they should be tried, starting after $lastUserId.
     *
     * @return list<int>
     */
    public function candidates(?int $lastUserId, string $day, int $limit): array;
}
