<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Authorization\LeadVisibility;
use App\Enums\UserRole;
use App\Models\Builders\LeadBuilder;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Runs the real list/filter queries (the same LeadBuilder scopes the admin
 * panel and API use) and prints timing plus the EXPLAIN plan of each, so
 * index usage can be verified on a large dataset (R8.4).
 */
final class BenchmarkLeadQueriesCommand extends Command
{
    protected $signature = 'leads:benchmark {--runs=5 : Timed runs per query (median reported)}';

    protected $description = 'Time the lead list queries per role/filter and show their EXPLAIN plans';

    public function handle(LeadVisibility $visibility): int
    {
        $admin = User::query()->where('role', UserRole::Admin->value)->firstOrFail();
        $manager = User::query()->where('role', UserRole::SalesManager->value)->whereHas('managedTeam')->firstOrFail();
        $agent = User::query()->activeAgents()->firstOrFail();

        $this->components->info(sprintf('leads table: %s rows', number_format(Lead::query()->count())));

        $cases = [
            'Admin: latest 25' => fn (): LeadBuilder => $visibility->apply(Lead::query(), $admin),
            'Admin: Unassigned' => fn (): LeadBuilder => $visibility->apply(Lead::query(), $admin)->unassigned(),
            'Admin: Overdue' => fn (): LeadBuilder => $visibility->apply(Lead::query(), $admin)->overdue(now()),
            'Admin: High Priority' => fn (): LeadBuilder => $visibility->apply(Lead::query(), $admin)->highPriority(),
            'Admin: search name' => fn (): LeadBuilder => $visibility->apply(Lead::query(), $admin)->search('Mona Adel'),
            'Admin: search phone' => fn (): LeadBuilder => $visibility->apply(Lead::query(), $admin)->search('+201200001'),
            'Manager: team leads' => fn (): LeadBuilder => $visibility->apply(Lead::query(), $manager),
            'Manager: team overdue' => fn (): LeadBuilder => $visibility->apply(Lead::query(), $manager)->overdue(now()),
            'Agent: My Leads' => fn (): LeadBuilder => $visibility->apply(Lead::query(), $agent)->assignedTo($agent),
            'Agent: high priority' => fn (): LeadBuilder => $visibility->apply(Lead::query(), $agent)->highPriority(),
            'Sweeper: due new leads' => fn (): LeadBuilder => Lead::query()->pendingEscalation(now()->subMinutes(30)),
        ];

        $rows = [];

        foreach ($cases as $label => $build) {
            $page = $build()->orderByDesc('leads.id')->limit(25);
            $count = $build();

            $rows[] = [
                $label,
                $this->medianMs(fn () => $page->get(['leads.id', 'leads.name', 'leads.status', 'leads.assigned_to', 'leads.next_follow_up_at'])),
                $this->medianMs(fn () => $count->count()),
                $this->plan($page),
            ];
        }

        $this->table(['Query', 'Page of 25 (ms)', 'COUNT(*) (ms)', 'EXPLAIN (table: type / key / rows)'], $rows);

        return self::SUCCESS;
    }

    private function medianMs(callable $run): string
    {
        $times = [];

        for ($i = 0; $i < (int) $this->option('runs'); $i++) {
            $start = hrtime(true);
            $run();
            $times[] = (hrtime(true) - $start) / 1e6;
        }

        sort($times);

        return number_format($times[intdiv(count($times), 2)], 1);
    }

    private function plan(LeadBuilder $query): string
    {
        $explain = DB::select('EXPLAIN '.$query->toRawSql());

        return collect($explain)
            ->map(fn (object $row): string => sprintf('%s: %s / %s / %s', $row->table, $row->type, $row->key ?? '-', number_format((int) $row->rows)))
            ->implode('; ');
    }
}
