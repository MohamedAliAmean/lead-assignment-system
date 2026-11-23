<?php

declare(strict_types=1);

namespace App\Authorization\Rules;

use App\Authorization\Contracts\LeadVisibilityRule;
use App\Models\Builders\LeadBuilder;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;

/**
 * Managers see leads assigned to the current members of the team they manage
 * (assumption A-9: visibility follows current membership).
 *
 * Built as one SQL statement with subqueries; MySQL runs it as a semi-join
 * that probes idx_leads_assignee_status.
 */
final class ManagerVisibility implements LeadVisibilityRule
{
    public function apply(LeadBuilder $query, User $user): LeadBuilder
    {
        return $query->whereIn(
            'leads.assigned_to',
            User::query()->select('users.id')->whereIn(
                'users.team_id',
                Team::query()->select('teams.id')->where('teams.manager_id', $user->getKey()),
            ),
        );
    }

    public function allows(User $user, Lead $lead): bool
    {
        if ($lead->assigned_to === null) {
            return false;
        }

        return User::query()
            ->whereKey($lead->assigned_to)
            ->whereHas('team', static fn ($team) => $team->where('manager_id', $user->getKey()))
            ->exists();
    }
}
