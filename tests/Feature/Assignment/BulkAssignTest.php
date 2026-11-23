<?php

declare(strict_types=1);

use App\Actions\Leads\BulkAssignLeads;
use App\Data\BulkAssignData;
use App\Events\LeadAssigned;
use App\Exceptions\Domain\AgentCapacityExceeded;
use App\Exceptions\Domain\AssigneeNotEligible;
use App\Exceptions\Domain\BulkAssignLimitExceeded;
use App\Exceptions\Domain\LeadsNotAccessible;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function bulkAssign(array $leadIds, User $target, User $actor): int
{
    return app(BulkAssignLeads::class)->handle(new BulkAssignData($leadIds, $target->id), $actor);
}

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->manager = User::factory()->manager()->create();
    $this->team = Team::factory()->managedBy($this->manager)->create();
    $this->teamAgent = User::factory()->agent()->inTeam($this->team)->capacity(5)->create();
    $this->otherTeamAgent = User::factory()->agent()->capacity(5)->create();
});

it('assigns the selected leads and charges the target capacity', function (): void {
    Event::fake([LeadAssigned::class]);
    $leads = Lead::factory()->count(3)->create();

    $moved = bulkAssign($leads->modelKeys(), $this->teamAgent, $this->admin);

    expect($moved)->toBe(3)
        ->and(Lead::query()->where('assigned_to', $this->teamAgent->id)->count())->toBe(3)
        ->and((int) DB::table('agent_daily_loads')->where('user_id', $this->teamAgent->id)->value('assigned_count'))->toBe(3);
    Event::assertDispatchedTimes(LeadAssigned::class, 3);
});

it('rejects the whole batch when it exceeds the target capacity', function (): void {
    $leads = Lead::factory()->count(6)->create();

    expect(fn () => bulkAssign($leads->modelKeys(), $this->teamAgent, $this->admin))
        ->toThrow(AgentCapacityExceeded::class);

    expect(Lead::query()->whereNotNull('assigned_to')->count())->toBe(0)
        ->and((int) DB::table('agent_daily_loads')->sum('assigned_count'))->toBe(0);
});

it('reports the remaining capacity when rejecting', function (): void {
    bulkAssign(Lead::factory()->count(4)->create()->modelKeys(), $this->teamAgent, $this->admin);

    try {
        bulkAssign(Lead::factory()->count(2)->create()->modelKeys(), $this->teamAgent, $this->admin);
        $this->fail('Expected capacity exception');
    } catch (AgentCapacityExceeded $e) {
        expect($e->remaining)->toBe(1)->and($e->requested)->toBe(2);
    }
});

it('does not charge capacity for leads already assigned to the target', function (): void {
    $already = Lead::factory()->count(2)->assignedTo($this->teamAgent)->create();
    $new = Lead::factory()->create();

    $moved = bulkAssign([...$already->modelKeys(), $new->id], $this->teamAgent, $this->admin);

    expect($moved)->toBe(1)
        ->and((int) DB::table('agent_daily_loads')->where('user_id', $this->teamAgent->id)->value('assigned_count'))->toBe(1);
});

it('does not let agents bulk assign', function (): void {
    $lead = Lead::factory()->assignedTo($this->teamAgent)->create();

    expect(fn () => bulkAssign([$lead->id], $this->teamAgent, $this->teamAgent))
        ->toThrow(AuthorizationException::class);
});

it('does not let a manager assign to an agent outside their team', function (): void {
    $lead = Lead::factory()->assignedTo($this->teamAgent)->create();

    expect(fn () => bulkAssign([$lead->id], $this->otherTeamAgent, $this->manager))
        ->toThrow(AssigneeNotEligible::class);
});

it('does not let a manager reassign leads they cannot see', function (): void {
    $visible = Lead::factory()->assignedTo($this->teamAgent)->create();
    $foreign = Lead::factory()->assignedTo($this->otherTeamAgent)->create();
    $teammate = User::factory()->agent()->inTeam($this->team)->create();

    expect(fn () => bulkAssign([$visible->id, $foreign->id], $teammate, $this->manager))
        ->toThrow(LeadsNotAccessible::class);

    // All-or-nothing: the visible lead was not moved either.
    expect($visible->fresh()->assigned_to)->toBe($this->teamAgent->id);
});

it('lets a manager move leads within their team', function (): void {
    $teammate = User::factory()->agent()->inTeam($this->team)->create();
    $lead = Lead::factory()->assignedTo($this->teamAgent)->create();

    expect(bulkAssign([$lead->id], $teammate, $this->manager))->toBe(1)
        ->and($lead->fresh()->assigned_to)->toBe($teammate->id);
});

it('refuses inactive or non-agent targets', function (): void {
    $lead = Lead::factory()->create();
    $inactive = User::factory()->agent()->inactive()->create();

    expect(fn () => bulkAssign([$lead->id], $inactive, $this->admin))->toThrow(AssigneeNotEligible::class)
        ->and(fn () => bulkAssign([$lead->id], $this->manager, $this->admin))->toThrow(AssigneeNotEligible::class);
});

it('caps the batch size', function (): void {
    config(['leads.bulk_assign_max' => 2]);

    expect(fn () => bulkAssign([1, 2, 3], $this->teamAgent, $this->admin))
        ->toThrow(BulkAssignLimitExceeded::class);
});
