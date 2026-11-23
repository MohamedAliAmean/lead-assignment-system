<?php

declare(strict_types=1);

namespace App\Authorization\Rules;

use App\Authorization\Contracts\LeadVisibilityRule;
use App\Models\Builders\LeadBuilder;
use App\Models\Lead;
use App\Models\User;

/**
 * Agents see only leads assigned to them.
 */
final class AgentVisibility implements LeadVisibilityRule
{
    public function apply(LeadBuilder $query, User $user): LeadBuilder
    {
        return $query->where('leads.assigned_to', $user->getKey());
    }

    public function allows(User $user, Lead $lead): bool
    {
        return $lead->assigned_to !== null && $lead->assigned_to === $user->getKey();
    }
}
