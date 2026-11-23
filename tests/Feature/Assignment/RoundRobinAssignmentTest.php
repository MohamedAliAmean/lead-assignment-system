<?php

declare(strict_types=1);

use App\Models\AssignmentCursor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('assigns new leads to active agents in strict round robin order', function (): void {
    $agents = User::factory()->agent()->count(3)->create();

    $assignees = createLeads(6);

    $ids = $agents->modelKeys();
    expect($assignees)->toBe([$ids[0], $ids[1], $ids[2], $ids[0], $ids[1], $ids[2]]);
});

it('continues the rotation from the cursor instead of restarting', function (): void {
    $agents = User::factory()->agent()->count(3)->create();
    AssignmentCursor::query()->where('pool', 'global')->update(['last_user_id' => $agents[1]->id]);

    expect(createLeads(2))->toBe([$agents[2]->id, $agents[0]->id]);
});

it('never assigns leads to admins or managers', function (): void {
    User::factory()->admin()->create();
    User::factory()->manager()->create();
    $agent = User::factory()->agent()->create();

    expect(array_unique(createLeads(3)))->toBe([$agent->id]);
});

it('creates the lead unassigned when no agent is eligible', function (): void {
    User::factory()->agent()->inactive()->create();

    $lead = createLead();

    expect($lead->assigned_to)->toBeNull()
        ->and($lead->assigned_at)->toBeNull()
        ->and(DB::table('agent_daily_loads')->count())->toBe(0);
});

it('records assignment time and a default follow-up deadline', function (): void {
    $this->travelTo(now()->startOfMinute());
    User::factory()->agent()->create();

    $lead = createLead();

    expect($lead->assigned_at->equalTo(now()))->toBeTrue()
        ->and($lead->next_follow_up_at->equalTo(now()->addMinutes(30)))->toBeTrue();
});

it('keeps the ledger equal to the real number of assigned leads', function (): void {
    $agents = User::factory()->agent()->count(2)->create();

    createLeads(5);

    foreach ($agents as $agent) {
        $ledger = (int) DB::table('agent_daily_loads')->where('user_id', $agent->id)->value('assigned_count');
        expect($ledger)->toBe($agent->assignedLeads()->count());
    }
});
