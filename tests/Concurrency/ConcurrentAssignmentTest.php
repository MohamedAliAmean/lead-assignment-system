<?php

declare(strict_types=1);

use App\Models\AssignmentCursor;
use App\Models\Lead;
use App\Models\LeadEscalation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Required proof of concurrency handling (requirement R9.1 / R3.5).
|
| Every test starts real, separate PHP processes (own runtime, own MySQL
| connection) that wait for the same start instant and then call the real
| Actions at once. Assertions check only the final database state, never
| timing, so the tests are deterministic.
*/

beforeEach(function (): void {
    // The rotation row is created by a migration; the per-test data reset
    // clears it, so put it back.
    DB::table('assignment_cursors')->insertOrIgnore(['pool' => AssignmentCursor::GLOBAL_POOL, 'updated_at' => now()]);
});

/**
 * @return list<list<string>>
 */
function createWorkers(int $workers, int $leadsPerWorker): array
{
    return array_map(
        static fn (int $worker): array => ['create', "--count={$leadsPerWorker}", "--worker={$worker}"],
        range(1, $workers),
    );
}

function ledgerCount(int $agentId): int
{
    return (int) DB::table('agent_daily_loads')->where('user_id', $agentId)->sum('assigned_count');
}

it('keeps Round Robin perfectly fair when 8 processes create 200 leads at once', function (): void {
    $agents = User::factory()->agent()->capacity(40)->count(5)->create();
    $inactive = User::factory()->agent()->inactive()->create();

    runConcurrently(createWorkers(workers: 8, leadsPerWorker: 25));

    $perAgent = Lead::query()->selectRaw('assigned_to, COUNT(*) AS total')->groupBy('assigned_to')->pluck('total', 'assigned_to');

    // Every lead has exactly one assignee, and none went to the inactive agent.
    expect(Lead::query()->count())->toBe(200)
        ->and(Lead::query()->whereNull('assigned_to')->count())->toBe(0)
        ->and($perAgent->has($inactive->id))->toBeFalse();

    // Exactly 40 each: no agent was skipped or given an extra turn.
    foreach ($agents as $agent) {
        expect((int) $perAgent[$agent->id])->toBe(40)
            ->and(ledgerCount($agent->id))->toBe(40); // the ledger never drifts
    }

    // Lead ids are allocated while the rotation lock is held, so in id order the
    // assignees must form a perfect repeating cycle. Any race would break it.
    $sequence = Lead::query()->orderBy('id')->pluck('assigned_to')->all();
    $cycle = array_slice($sequence, 0, 5);
    expect($cycle)->toEqualCanonicalizing($agents->modelKeys());
    foreach ($sequence as $index => $agentId) {
        expect($agentId)->toBe($cycle[$index % 5]);
    }
});

it('never exceeds daily capacity under contention', function (): void {
    // 5 agents x 30 = 150 slots for 200 concurrent leads.
    $agents = User::factory()->agent()->capacity(30)->count(5)->create();

    runConcurrently(createWorkers(workers: 8, leadsPerWorker: 25));

    expect(Lead::query()->count())->toBe(200)
        ->and(Lead::query()->whereNotNull('assigned_to')->count())->toBe(150)
        ->and(Lead::query()->whereNull('assigned_to')->count())->toBe(50);

    foreach ($agents as $agent) {
        expect(Lead::query()->where('assigned_to', $agent->id)->count())->toBe(30)
            ->and(ledgerCount($agent->id))->toBe(30);
    }
});

it('keeps capacity correct when bulk assignment races with automatic assignment', function (): void {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->agent()->capacity(20)->create();
    User::factory()->agent()->capacity(100)->count(2)->create();

    // 40 unassigned leads that two bulk workers will both try to give to $target.
    $pool = Lead::factory()->count(40)->create()->modelKeys();
    [$firstHalf, $secondHalf] = array_chunk($pool, 20);

    runConcurrently([
        ...createWorkers(workers: 4, leadsPerWorker: 15), // 60 auto-assigned leads, ~20 aimed at $target
        ['bulk-assign', '--lead-ids='.implode(',', $firstHalf), '--batch=4', "--assignee={$target->id}", "--actor={$admin->id}"],
        ['bulk-assign', '--lead-ids='.implode(',', $secondHalf), '--batch=4', "--assignee={$target->id}", "--actor={$admin->id}"],
    ]);

    $actual = Lead::query()->where('assigned_to', $target->id)->count();

    // Demand on $target was ~60 slots; capacity is 20 and must hold exactly.
    expect($actual)->toBe(20)
        ->and(ledgerCount($target->id))->toBe($actual);
});

it('serializes assignments on the rotation row lock', function (): void {
    User::factory()->agent()->create();
    config(['database.connections.mysql_second' => config('database.connections.mysql')]);

    // Connection A holds the rotation lock inside an open transaction...
    DB::beginTransaction();
    DB::table('assignment_cursors')->where('pool', 'global')->lockForUpdate()->first();

    // ...so connection B, trying to assign, has to wait and times out.
    $second = DB::connection('mysql_second');
    $second->statement('SET SESSION innodb_lock_wait_timeout = 1');

    try {
        // Exactly what LeadAssigner::claimNextAgent() does first, on another connection.
        $second->transaction(function () use ($second): void {
            $second->table('assignment_cursors')->where('pool', 'global')->lockForUpdate()->first();
        });
        $this->fail('Expected a lock wait timeout.');
    } catch (QueryException $e) {
        expect($e->errorInfo[1] ?? null)->toBe(1205); // ER_LOCK_WAIT_TIMEOUT
    } finally {
        DB::rollBack();
        $second->disconnect();
    }
});

it('escalates exactly once when 4 processes run the job for the same lead at once', function (): void {
    $manager = User::factory()->manager()->create();
    $team = Team::factory()->managedBy($manager)->create();
    $agent = User::factory()->agent()->inTeam($team)->create();
    $lead = Lead::factory()->assignedTo($agent)->create();
    // Backdate creation AND the "entered New" audit entry: the escalation clock
    // starts when the lead last entered New.
    $lead->forceFill(['created_at' => now()->subHour()])->saveQuietly();
    DB::table('lead_status_logs')->where('lead_id', $lead->id)->update(['changed_at' => now()->subHour()]);

    runConcurrently(array_fill(0, 4, ['escalate', "--lead={$lead->id}", '--count=3']));

    expect(LeadEscalation::query()->where('lead_id', $lead->id)->count())->toBe(1)
        ->and($manager->notifications()->count())->toBe(1);
});
