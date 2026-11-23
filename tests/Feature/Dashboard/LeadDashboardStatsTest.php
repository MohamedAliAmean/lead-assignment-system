<?php

declare(strict_types=1);

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadEscalation;
use App\Models\Team;
use App\Models\User;
use App\Queries\LeadDashboardStats;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->manager = User::factory()->manager()->create();
    $this->team = Team::factory()->managedBy($this->manager)->create();
    $this->agent = User::factory()->agent()->inTeam($this->team)->capacity(10)->create();
    $this->outsider = User::factory()->agent()->capacity(5)->create();

    Lead::factory()->count(2)->assignedTo($this->agent)->create();                       // open, not overdue
    Lead::factory()->assignedTo($this->agent)->overdue()->create();                       // open + overdue
    Lead::factory()->assignedTo($this->agent)->overdue()->status(LeadStatus::Won)->create(); // closed: never overdue
    Lead::factory()->count(3)->assignedTo($this->outsider)->overdue()->create();
    Lead::factory()->count(2)->create();                                                  // unassigned
    $this->stats = app(LeadDashboardStats::class);
});

it('scopes the summary to what each role can see', function (): void {
    expect($this->stats->summary($this->agent))->toMatchArray(['open' => 3, 'overdue' => 1, 'new_today' => 4, 'unassigned' => null])
        ->and($this->stats->summary($this->manager))->toMatchArray(['open' => 3, 'overdue' => 1, 'unassigned' => null])
        ->and($this->stats->summary($this->admin))->toMatchArray(['open' => 8, 'overdue' => 4, 'new_today' => 9, 'unassigned' => 2]);
});

it('counts only escalations the user can see', function (): void {
    $own = Lead::query()->where('assigned_to', $this->agent->id)->first();
    $foreign = Lead::query()->where('assigned_to', $this->outsider->id)->first();
    LeadEscalation::query()->create(['lead_id' => $own->id, 'escalated_at' => now()]);
    LeadEscalation::query()->create(['lead_id' => $foreign->id, 'escalated_at' => now()]);

    expect($this->stats->summary($this->agent)['escalated_today'])->toBe(1)
        ->and($this->stats->summary($this->admin)['escalated_today'])->toBe(2);
});

it('returns a complete daily series and status breakdown', function (): void {
    $series = $this->stats->createdPerDay($this->admin, 14);
    $breakdown = $this->stats->statusBreakdown($this->agent);

    expect($series)->toHaveCount(14)
        ->and(array_sum($series))->toBe(9)
        ->and(array_key_last($series))->toBe(now('Africa/Cairo')->toDateString())
        ->and($breakdown)->toHaveKeys(LeadStatus::values())
        ->and($breakdown['new'])->toBe(3)
        ->and($breakdown['won'])->toBe(1);
});

it('lists the most overdue visible leads first', function (): void {
    $oldest = Lead::factory()->assignedTo($this->agent)->create(['next_follow_up_at' => now()->subDays(3)]);

    $list = $this->stats->needsAttention($this->agent);

    expect($list->first()->id)->toBe($oldest->id)
        ->and($list->pluck('assigned_to')->unique()->all())->toBe([$this->agent->id]);
});

it('shows capacity for the agents the user may assign to', function (): void {
    expect($this->stats->capacityToday($this->manager)->pluck('id')->all())->toBe([$this->agent->id])
        ->and($this->stats->capacityToday($this->admin)->pluck('id')->all())->toEqualCanonicalizing([$this->agent->id, $this->outsider->id])
        ->and($this->stats->capacityToday($this->agent))->toBeEmpty();
});

it('runs one query per metric and serves repeats from the cache', function (): void {
    Cache::flush();
    DB::enableQueryLog();

    $this->stats->statusBreakdown($this->admin);
    $first = count(DB::getQueryLog());
    $this->stats->statusBreakdown($this->admin);

    expect($first)->toBe(1)->and(count(DB::getQueryLog()))->toBe(1);
});

it('never shares a cache entry between different visibility scopes', function (): void {
    $this->stats->summary($this->admin);

    expect($this->stats->summary($this->agent)['open'])->toBe(3);
});

describe('API', function (): void {
    it('returns the current user with today\'s capacity', function (): void {
        Sanctum::actingAs($this->agent);

        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.role', 'sales_agent')
            ->assertJsonPath('data.team.id', $this->team->id)
            ->assertJsonPath('data.can_assign', false)
            ->assertJsonPath('data.capacity_today.capacity', 10);
    });

    it('lists assignable agents per role', function (): void {
        Sanctum::actingAs($this->manager);
        $this->getJson('/api/v1/assignable-agents')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->agent->id);

        Sanctum::actingAs($this->agent);
        $this->getJson('/api/v1/assignable-agents')->assertOk()->assertJsonCount(0, 'data');
    });

    it('serves the dashboard stats scoped to the caller', function (): void {
        Sanctum::actingAs($this->agent);

        $this->getJson('/api/v1/dashboard/stats')->assertOk()
            ->assertJsonPath('data.summary.open', 3)
            ->assertJsonPath('data.summary.unassigned', null)
            ->assertJsonCount(14, 'data.created_per_day')
            ->assertJsonCount(1, 'data.needs_attention');
    });
});
