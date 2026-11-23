<?php

declare(strict_types=1);

namespace App\Policies;

use App\Authorization\AssigneeEligibility;
use App\Authorization\LeadVisibility;
use App\Models\Lead;
use App\Models\User;

/**
 * Record-level authorization (R6). Uses the same visibility rules as the query
 * scope, so lists and direct access always agree.
 */
final readonly class LeadPolicy
{
    public function __construct(
        private LeadVisibility $visibility,
        private AssigneeEligibility $eligibility,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Lead $lead): bool
    {
        return $this->visibility->allows($user, $lead);
    }

    public function create(User $user): bool
    {
        return $user->is_active && ($user->isAdmin() || $user->isManager());
    }

    public function update(User $user, Lead $lead): bool
    {
        return $this->visibility->allows($user, $lead);
    }

    public function changeStatus(User $user, Lead $lead): bool
    {
        return $this->visibility->allows($user, $lead);
    }

    /**
     * May the user assign leads at all (bulk action visibility). Per-lead and
     * per-target checks happen inside BulkAssignLeads.
     */
    public function assign(User $user): bool
    {
        return $this->eligibility->canAssign($user);
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }
}
