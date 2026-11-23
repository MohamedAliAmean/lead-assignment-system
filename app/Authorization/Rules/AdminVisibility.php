<?php

declare(strict_types=1);

namespace App\Authorization\Rules;

use App\Authorization\Contracts\LeadVisibilityRule;
use App\Models\Builders\LeadBuilder;
use App\Models\Lead;
use App\Models\User;

final class AdminVisibility implements LeadVisibilityRule
{
    public function apply(LeadBuilder $query, User $user): LeadBuilder
    {
        return $query;
    }

    public function allows(User $user, Lead $lead): bool
    {
        return true;
    }
}
