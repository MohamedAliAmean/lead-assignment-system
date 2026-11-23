<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who may manually assign leads to whom (R6.6):
 *  - admins: to any active sales agent;
 *  - managers: to active sales agents in the team they manage;
 *  - agents: never.
 */
final class AssigneeEligibility
{
    public function canAssign(User $actor): bool
    {
        return $actor->is_active && ($actor->isAdmin() || $actor->isManager());
    }

    public function canAssignTo(User $actor, User $target): bool
    {
        return $this->assignableAgents($actor)->whereKey($target->getKey())->exists();
    }

    /**
     * Agents the actor may assign to. Also used to build the select options in
     * the admin panel, so the UI and the server rule are the same query.
     *
     * @return Builder<User>
     */
    public function assignableAgents(User $actor): Builder
    {
        $query = User::query()->activeAgents();

        return match (true) {
            ! $this->canAssign($actor) => $query->whereRaw('1 = 0'),
            $actor->role === UserRole::Admin => $query,
            default => $query->whereHas(
                'team',
                static fn ($team) => $team->where('manager_id', $actor->getKey()),
            ),
        };
    }

    /**
     * Assignable agents with their remaining capacity for $day, in ONE query
     * (no per-agent lookups). Used for assignee pickers.
     *
     * @return Collection<int, array{id: int, name: string, remaining: int}>
     */
    public function assignableAgentsWithRemaining(User $actor, string $day): Collection
    {
        return $this->assignableAgents($actor)
            ->leftJoin('agent_daily_loads as loads', function ($join) use ($day): void {
                $join->on('loads.user_id', '=', 'users.id')->where('loads.load_date', '=', $day);
            })
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.daily_capacity', 'loads.assigned_count'])
            ->map(static fn (User $agent): array => [
                'id' => $agent->id,
                'name' => $agent->name,
                'remaining' => max(0, $agent->daily_capacity - (int) $agent->getAttribute('assigned_count')),
            ]);
    }
}
