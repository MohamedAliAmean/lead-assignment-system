<?php

declare(strict_types=1);

namespace App\Authorization\Contracts;

use App\Models\Builders\LeadBuilder;
use App\Models\Lead;
use App\Models\User;

/**
 * Which leads a user may see. The set-level and record-level checks live in
 * the same class so the list scope and the policy can never disagree (§8.2).
 */
interface LeadVisibilityRule
{
    public function apply(LeadBuilder $query, User $user): LeadBuilder;

    public function allows(User $user, Lead $lead): bool;
}
