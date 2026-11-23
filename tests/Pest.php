<?php

declare(strict_types=1);

use App\Actions\Leads\CreateLead;
use App\Data\CreateLeadData;
use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
| Helpers
*/

/**
 * Create a lead through the real CreateLead action (Round Robin, audit, escalation).
 */
function createLead(array $overrides = [], ?User $actor = null): Lead
{
    return app(CreateLead::class)->handle(new CreateLeadData(
        name: $overrides['name'] ?? fake()->name(),
        phone: $overrides['phone'] ?? '+2010'.fake()->unique()->numerify('########'),
        source: $overrides['source'] ?? LeadSource::Website,
        priority: $overrides['priority'] ?? LeadPriority::Medium,
        nextFollowUpAt: $overrides['next_follow_up_at'] ?? null,
        externalRef: $overrides['external_ref'] ?? null,
    ), $actor);
}

/**
 * @return list<int|null> assignee id per created lead, in creation order
 */
function createLeads(int $count): array
{
    $assignees = [];

    for ($i = 0; $i < $count; $i++) {
        $assignees[] = createLead()->assigned_to;
    }

    return $assignees;
}

/*
| Concurrency and Integration tests need data that is really committed:
| parallel processes cannot see another connection's open transaction, and
| InnoDB only updates FULLTEXT indexes on commit. A per-test wrapping
| transaction would hide that data, so these tests reset the data between
| tests instead (Laravel's DatabaseTruncation trait).
*/
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Concurrency', 'Integration');

/**
 * Run the given worker commands as parallel OS processes that all start at the
 * same instant. Fails the test if any worker fails.
 *
 * @param  list<list<string>>  $workers  arguments for testing:concurrency-worker
 */
function runConcurrently(array $workers, float $startDelay = 2.0): void
{
    $startAt = sprintf('%.6F', microtime(true) + $startDelay);
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        'DB_DATABASE' => config('database.connections.mysql.database'),
        'QUEUE_CONNECTION' => 'null',
        'CACHE_STORE' => 'array',
    ];

    $results = Process::pool(function ($pool) use ($workers, $startAt, $env): void {
        foreach ($workers as $arguments) {
            $pool->path(base_path())->env($env)->timeout(120)
                ->command([PHP_BINARY, 'artisan', 'testing:concurrency-worker', ...$arguments, "--start-at={$startAt}"]);
        }
    })->start()->wait();

    foreach ($results as $index => $result) {
        expect($result->successful())->toBeTrue("Worker {$index} failed: ".$result->errorOutput().$result->output());
    }
}
