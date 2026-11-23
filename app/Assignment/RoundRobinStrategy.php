<?php

declare(strict_types=1);

namespace App\Assignment;

use App\Assignment\Contracts\AssignmentStrategy;
use App\Enums\UserRole;
use Illuminate\Database\ConnectionInterface;

/**
 * Plain Round Robin: agents ordered by id, starting with the first id after the
 * last assigned agent, then wrapping around (design §6.2).
 */
final readonly class RoundRobinStrategy implements AssignmentStrategy
{
    public function __construct(private ConnectionInterface $db) {}

    public function candidates(?int $lastUserId, string $day, int $limit): array
    {
        $last = $lastUserId ?? 0;

        return $this->db->table('users')
            ->leftJoin('agent_daily_loads as loads', function ($join) use ($day): void {
                $join->on('loads.user_id', '=', 'users.id')->where('loads.load_date', '=', $day);
            })
            ->where('users.role', UserRole::SalesAgent->value)
            ->where('users.is_active', true)
            ->whereRaw('COALESCE(loads.assigned_count, 0) < users.daily_capacity')
            // Agents after the cursor first (0), then the wrap-around (1).
            ->orderByRaw('(users.id <= ?) ASC', [$last])
            ->orderBy('users.id')
            ->limit($limit)
            ->pluck('users.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
