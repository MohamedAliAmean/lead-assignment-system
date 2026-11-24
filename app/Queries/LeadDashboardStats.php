<?php

declare(strict_types=1);

namespace App\Queries;

use App\Authorization\AssigneeEligibility;
use App\Authorization\LeadVisibility;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadEscalation;
use App\Models\User;
use App\Support\BusinessClock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;

/**
 * Dashboard numbers shared by the Filament widgets and the API (frontend spec
 * §3.1), so both UIs show exactly the same, equally authorized figures.
 *
 * - Every metric starts from LeadVisibility::apply(): same rules as the lists.
 * - Every metric is one indexed aggregate query; rows are never loaded into PHP.
 * - Results are cached briefly per visibility scope, so a dashboard full of
 *   widgets costs at most one query per metric per minute.
 */
final readonly class LeadDashboardStats
{
    public const int TTL_SECONDS = 60;

    public function __construct(
        private BusinessClock $clock,
        private AssigneeEligibility $eligibility,
        private LeadVisibility $visibility,
        private Cache $cache,
    ) {}

    /**
     * @return array{open: int, overdue: int, new_today: int, unassigned: int|null, escalated_today: int}
     */
    public function summary(User $user): array
    {
        return $this->remember($user, 'summary', function () use ($user): array {
            $now = $this->clock->now();
            $today = $this->clock->startOfToday();
            $visible = fn () => $this->visibility->apply(Lead::query(), $user);

            // Separate COUNTs on purpose: each one uses its own index, which is
            // ~40% faster on 500k rows than one CASE WHEN aggregate that must
            // scan the whole table (measured, technical note §5).
            return [
                'open' => $visible()->whereIn('leads.status', LeadStatus::openValues())->count(),
                'overdue' => $visible()->overdue($now)->count(),
                'new_today' => $visible()->where('leads.created_at', '>=', $today)->count(),
                // Only admins can see unassigned leads at all.
                'unassigned' => $user->isAdmin() ? $visible()->unassigned()->count() : null,
                'escalated_today' => LeadEscalation::query()
                    ->where('escalated_at', '>=', $today)
                    ->whereIn('lead_id', $visible()->select('leads.id'))
                    ->count(),
            ];
        });
    }

    /**
     * Leads created per business day for the last $days days (oldest first,
     * missing days filled with 0).
     *
     * @return array<string, int> Y-m-d => count
     */
    public function createdPerDay(User $user, int $days = 14): array
    {
        return $this->remember($user, "created-per-day:{$days}", function () use ($user, $days): array {
            $from = $this->clock->startOfToday()->subDays($days - 1);
            $offset = $this->clock->utcOffset();

            $counts = $this->visibility->apply(Lead::query(), $user)->toBase()
                ->where('leads.created_at', '>=', $from)
                ->selectRaw("DATE(CONVERT_TZ(leads.created_at, '+00:00', ?)) AS day, COUNT(*) AS total", [$offset])
                ->groupBy('day')
                ->pluck('total', 'day');

            $series = [];
            $cursor = $this->clock->now()->setTimezone($this->clock->timezone())->startOfDay()->subDays($days - 1);

            for ($i = 0; $i < $days; $i++) {
                $key = $cursor->addDays($i)->toDateString();
                $series[$key] = (int) ($counts[$key] ?? 0);
            }

            return $series;
        });
    }

    /**
     * @return array<string, int> status value => count (every status present)
     */
    public function statusBreakdown(User $user): array
    {
        return $this->remember($user, 'status-breakdown', function () use ($user): array {
            $counts = $this->visibility->apply(Lead::query(), $user)->toBase()
                ->selectRaw('leads.status AS status, COUNT(*) AS total')
                ->groupBy('leads.status')
                ->pluck('total', 'status');

            $result = [];
            foreach (LeadStatus::cases() as $status) {
                $result[$status->value] = (int) ($counts[$status->value] ?? 0);
            }

            return $result;
        });
    }

    /**
     * Most overdue open leads first. Not cached: it is the actionable list and
     * a single indexed LIMIT query.
     *
     * @return Collection<int, Lead>
     */
    public function needsAttention(User $user, int $limit = 10): Collection
    {
        return $this->visibility->apply(Lead::query(), $user)
            ->overdue($this->clock->now())
            ->with('assignedUser:id,name')
            ->orderBy('leads.next_follow_up_at')
            ->limit($limit)
            ->get(); // at most $limit rows, all columns so the API resource can render them
    }

    /**
     * Today's capacity usage of the agents the user may assign to (admins: all
     * agents, managers: their team, agents: none).
     *
     * @return Collection<int, array{id: int, name: string, capacity: int, used: int, remaining: int}>
     */
    public function capacityToday(User $user): Collection
    {
        return collect($this->remember($user, 'capacity-today', fn (): array => $this->eligibility
            ->assignableAgentsWithRemaining($user, $this->clock->today())
            ->values()
            ->all()));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $compute
     * @return T
     */
    private function remember(User $user, string $metric, callable $compute): mixed
    {
        $key = sprintf('dashboard:%s:%s:%s', $this->scope($user), $metric, $this->clock->today());

        return $this->cache->remember($key, self::TTL_SECONDS, $compute);
    }

    /**
     * Cache scope = what the user can see. Users with the same visibility share
     * an entry; different visibility never shares one.
     */
    private function scope(User $user): string
    {
        return match (true) {
            ! $user->is_active => 'none',
            $user->isAdmin() => 'admin',
            default => $user->role->value.':'.$user->getKey(),
        };
    }
}
