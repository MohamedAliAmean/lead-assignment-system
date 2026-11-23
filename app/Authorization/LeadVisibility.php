<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Authorization\Contracts\LeadVisibilityRule;
use App\Models\Builders\LeadBuilder;
use App\Models\Lead;
use App\Models\User;

/**
 * Picks the visibility rule for a user's role. Fails closed: inactive users and
 * roles without a rule see nothing.
 */
final readonly class LeadVisibility
{
    /**
     * @param  array<string, LeadVisibilityRule>  $rules  keyed by UserRole value
     */
    public function __construct(private array $rules) {}

    public function apply(LeadBuilder $query, User $user): LeadBuilder
    {
        $rule = $this->ruleFor($user);

        if ($rule === null) {
            return $query->whereRaw('1 = 0');
        }

        return $rule->apply($query, $user);
    }

    public function allows(User $user, Lead $lead): bool
    {
        return $this->ruleFor($user)?->allows($user, $lead) ?? false;
    }

    private function ruleFor(User $user): ?LeadVisibilityRule
    {
        if (! $user->is_active) {
            return null;
        }

        return $this->rules[$user->role->value] ?? null;
    }
}
