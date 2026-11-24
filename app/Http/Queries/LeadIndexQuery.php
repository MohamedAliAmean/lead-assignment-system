<?php

declare(strict_types=1);

namespace App\Http\Queries;

use App\Authorization\LeadVisibility;
use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use App\Http\Requests\Api\V1\ListLeadsRequest;
use App\Models\Builders\LeadBuilder;
use App\Models\Lead;
use App\Models\User;

/**
 * Maps API query parameters onto the same LeadBuilder scopes the admin panel
 * filters use, so both channels filter identically.
 */
final readonly class LeadIndexQuery
{
    public function __construct(private LeadVisibility $visibility) {}

    public function for(User $user, ListLeadsRequest $request): LeadBuilder
    {
        $query = $this->visibility->apply(Lead::query(), $user)
            ->with('assignedUser:id,name')
            ->orderByDesc('leads.id');

        if ($request->flag('mine')) {
            $query->assignedTo($user);
        }

        if ($request->flag('unassigned')) {
            $query->unassigned();
        }

        if ($request->flag('overdue')) {
            $query->overdue(now());
        }

        $priority = $request->input('filter.priority');
        if ($priority === 'high') {
            $query->highPriority();
        } elseif ($priority !== null) {
            $query->where('leads.priority', LeadPriority::from((int) $priority)->value);
        }

        if ($request->filled('filter.status')) {
            $query->withStatus(LeadStatus::from((string) $request->input('filter.status')));
        }

        return $query->search($request->input('search'));
    }
}
