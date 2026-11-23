<?php

declare(strict_types=1);

namespace App\Assignment;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Tracks how many leads each agent received per business day and enforces the
 * daily capacity atomically (design §6.3).
 */
final readonly class CapacityLedger
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * Reserve $count slots for the agent on $day.
     *
     * The capacity check and the increment are ONE conditional UPDATE. InnoDB
     * evaluates its WHERE clause against the latest committed row and locks it,
     * so two competing reservations can never both pass the check. Activity and
     * role are checked in the same statement, so a deactivated agent is
     * rejected even if they were eligible a moment ago.
     */
    public function tryReserve(int $agentId, string $day, int $count = 1): bool
    {
        if ($count < 1) {
            return true;
        }

        $this->db->statement(
            'INSERT INTO agent_daily_loads (user_id, load_date, assigned_count) VALUES (?, ?, 0)
             ON DUPLICATE KEY UPDATE assigned_count = assigned_count',
            [$agentId, $day],
        );

        $affected = $this->db->update(
            'UPDATE agent_daily_loads AS l
             INNER JOIN users AS u ON u.id = l.user_id
             SET l.assigned_count = l.assigned_count + ?
             WHERE l.user_id = ? AND l.load_date = ?
               AND u.is_active = 1 AND u.role = ?
               AND l.assigned_count + ? <= u.daily_capacity',
            [$count, $agentId, $day, UserRole::SalesAgent->value, $count],
        );

        return $affected === 1;
    }

    public function used(int $agentId, string $day): int
    {
        return (int) $this->db->table('agent_daily_loads')
            ->where('user_id', $agentId)
            ->where('load_date', $day)
            ->value('assigned_count');
    }

    public function remaining(User $agent, string $day): int
    {
        return max(0, $agent->daily_capacity - $this->used($agent->getKey(), $day));
    }
}
