<?php

declare(strict_types=1);

namespace App\Escalation;

use App\Enums\UserRole;
use App\Escalation\Contracts\EscalationRecipientResolver;
use App\Models\Lead;
use App\Models\User;

/**
 * Assumption A-5: the active manager of the assigned agent's team. If the lead
 * is unassigned, or there is no active manager, all active admins.
 */
final class TeamManagerRecipientResolver implements EscalationRecipientResolver
{
    public function resolve(Lead $lead): EscalationTarget
    {
        $manager = $lead->assigned_to === null ? null : User::query()
            ->where('role', UserRole::SalesManager->value)
            ->where('is_active', true)
            ->whereHas('managedTeam.members', static fn ($members) => $members->whereKey($lead->assigned_to))
            ->first();

        if ($manager !== null) {
            return new EscalationTarget($manager, collect([$manager]));
        }

        return new EscalationTarget(
            null,
            User::query()->where('role', UserRole::Admin->value)->where('is_active', true)->get(),
        );
    }
}
