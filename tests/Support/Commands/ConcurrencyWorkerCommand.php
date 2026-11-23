<?php

declare(strict_types=1);

namespace Tests\Support\Commands;

use App\Actions\Leads\BulkAssignLeads;
use App\Actions\Leads\CreateLead;
use App\Actions\Leads\EscalateLead;
use App\Data\BulkAssignData;
use App\Data\CreateLeadData;
use App\Enums\LeadSource;
use App\Exceptions\Domain\DomainException;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Test-only worker used by tests/Concurrency. Each test spawns several of
 * these as separate OS processes (separate PHP runtimes and DB connections).
 * All workers sleep until the same --start-at instant, then hammer the real
 * Actions at once, so the database locks are actually contended.
 *
 * Registered only when APP_ENV=testing (see AppServiceProvider).
 */
final class ConcurrencyWorkerCommand extends Command
{
    protected $signature = 'testing:concurrency-worker
        {operation : create|bulk-assign|escalate}
        {--start-at= : Unix timestamp (float) all workers wait for}
        {--count=1 : Number of operations to perform}
        {--worker=0 : Worker number, used for unique test data}
        {--lead-ids= : Comma-separated lead ids (bulk-assign)}
        {--batch=5 : Leads per bulk-assign call}
        {--assignee= : Target agent id (bulk-assign)}
        {--actor= : Acting user id (bulk-assign)}
        {--lead= : Lead id (escalate)}';

    protected $hidden = true;

    public function handle(): int
    {
        $startAt = (float) $this->option('start-at');

        if ($startAt > microtime(true)) {
            time_sleep_until($startAt);
        }

        $count = (int) $this->option('count');

        match ($this->argument('operation')) {
            'create' => $this->create($count),
            'bulk-assign' => $this->bulkAssign(),
            'escalate' => $this->escalate($count),
        };

        return self::SUCCESS;
    }

    private function create(int $count): void
    {
        $action = app(CreateLead::class);
        $worker = (int) $this->option('worker');

        for ($i = 0; $i < $count; $i++) {
            $action->handle(new CreateLeadData(
                name: "Worker {$worker} lead {$i}",
                phone: sprintf('+2011%03d%05d', $worker, $i),
                source: LeadSource::Api,
            ));
        }
    }

    private function bulkAssign(): void
    {
        $action = app(BulkAssignLeads::class);
        $actor = User::query()->findOrFail((int) $this->option('actor'));
        $ids = array_map('intval', explode(',', (string) $this->option('lead-ids')));

        foreach (array_chunk($ids, (int) $this->option('batch')) as $batch) {
            try {
                $action->handle(new BulkAssignData($batch, (int) $this->option('assignee')), $actor);
            } catch (DomainException) {
                // Expected once the agent is full; the test checks the final state.
            }
        }
    }

    private function escalate(int $count): void
    {
        $action = app(EscalateLead::class);

        for ($i = 0; $i < $count; $i++) {
            $action->handle((int) $this->option('lead'));
        }
    }
}
