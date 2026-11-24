<?php

declare(strict_types=1);

namespace App\Models\Builders;

use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Named, reusable lead queries. Filament filters and API query parameters both
 * call these, so each filter is defined exactly once (design §5).
 *
 * Authorization scoping is NOT here: it is applied by the injected
 * LeadVisibility service ($visibility->apply($query, $user)), so this class has
 * no hidden dependencies (no service locator).
 *
 * @extends Builder<Lead>
 */
final class LeadBuilder extends Builder
{
    public function assignedTo(User|int $user): self
    {
        return $this->where('leads.assigned_to', $user instanceof User ? $user->getKey() : $user);
    }

    public function unassigned(): self
    {
        return $this->whereNull('leads.assigned_to');
    }

    /**
     * Open leads whose follow-up time has passed.
     */
    public function overdue(CarbonInterface $now): self
    {
        return $this->where('leads.next_follow_up_at', '<', $now)
            ->whereIn('leads.status', LeadStatus::openValues());
    }

    public function highPriority(): self
    {
        return $this->where('leads.priority', LeadPriority::High->value);
    }

    public function withStatus(LeadStatus ...$statuses): self
    {
        return $this->whereIn('leads.status', array_map(
            static fn (LeadStatus $status): string => $status->value,
            $statuses,
        ));
    }

    /**
     * Leads still "new" that were created at or before $createdBefore and have
     * not been escalated yet (escalation sweeper, design §9.3).
     */
    public function pendingEscalation(CarbonInterface $createdBefore): self
    {
        return $this->where('leads.status', LeadStatus::New->value)
            ->where('leads.created_at', '<=', $createdBefore)
            ->whereDoesntHave('escalation');
    }

    /**
     * Index-friendly search: phone numbers use a prefix match on idx_leads_phone,
     * names use the FULLTEXT index. A leading-wildcard LIKE is never used.
     */
    public function search(?string $term): self
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $this;
        }

        if (preg_match('/^\+?[\d\s\-]+$/', $term) === 1) {
            $digits = preg_replace('/[^\d+]/', '', $term) ?? '';

            return $this->where('leads.phone', 'like', $digits.'%');
        }

        $words = preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $term) ?? '') ?: [];
        $words = array_values(array_filter($words, static fn (string $word): bool => mb_strlen($word) >= 3));

        if ($words === []) {
            // Below InnoDB's FULLTEXT minimum token size: fall back to a prefix match.
            return $this->where('leads.name', 'like', addcslashes($term, '%_\\').'%');
        }

        $query = implode(' ', array_map(static fn (string $word): string => '+'.$word.'*', $words));

        return $this->whereFullText('leads.name', $query, ['mode' => 'boolean']);
    }
}
