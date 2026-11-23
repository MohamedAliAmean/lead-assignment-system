<?php

declare(strict_types=1);

use App\Actions\Leads\BulkAssignLeads;
use App\Actions\Leads\ChangeLeadStatus;
use App\Actions\Leads\EscalateLead;
use App\Actions\Leads\UpdateLead;
use App\Data\BulkAssignData;
use App\Data\UpdateLeadData;
use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\WebhookEvent;
use App\Exceptions\Domain\LeadsNotAccessible;
use App\Jobs\EscalateStaleLead;
use App\Models\AssignmentCursor;
use App\Models\Lead;
use App\Models\LeadEscalation;
use App\Models\LeadStatusLog;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Queries\LeadDashboardStats;
use App\Webhooks\OutboundUrlGuard;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
| 30 edge-case scenarios on top of the main suite. Each one targets a corner
| the other tests do not cover directly.
*/

function scenarioLedger(User $agent): int
{
    return (int) DB::table('agent_daily_loads')->where('user_id', $agent->id)->sum('assigned_count');
}

function scenarioBulk(array $leadIds, User $target, User $actor): int
{
    return app(BulkAssignLeads::class)->handle(new BulkAssignData($leadIds, $target->id), $actor);
}

function scenarioEscalate(int $leadId): bool
{
    return app(EscalateLead::class)->handle($leadId);
}

/**
 * @param  array<string, mixed>  $payload
 */
function scenarioInbound(array $payload, string $source, string $secret, ?int $timestamp = null): TestResponse
{
    $timestamp ??= now()->getTimestamp();
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return test()->call('POST', '/api/v1/webhooks/leads', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_WEBHOOK_SOURCE' => $source,
        'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_WEBHOOK_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
    ], content: $body);
}

// ---------------------------------------------------------------- Assignment

it('S01: continues the rotation across days and skips a full agent', function (): void {
    [$a, $b, $c] = [
        User::factory()->agent()->capacity(1)->create(),
        User::factory()->agent()->capacity(5)->create(),
        User::factory()->agent()->capacity(5)->create(),
    ];

    expect(createLeads(5))->toBe([$a->id, $b->id, $c->id, $b->id, $c->id]);

    $this->travel(1)->days();

    expect(createLeads(3))->toBe([$a->id, $b->id, $c->id]);
});

it('S02: a reactivated agent rejoins the rotation at their position', function (): void {
    [$a, $b, $c] = User::factory()->agent()->count(3)->create()->all();

    $b->update(['is_active' => false]);
    $first = createLeads(2);
    $b->update(['is_active' => true]);

    expect([...$first, ...createLeads(3)])->toBe([$a->id, $c->id, $a->id, $b->id, $c->id]);
});

it('S03: a newly hired agent joins the rotation immediately', function (): void {
    [$a, $c] = User::factory()->agent()->count(2)->create()->all();
    createLeads(1); // -> a
    $new = User::factory()->agent()->create();

    expect(createLeads(3))->toBe([$c->id, $new->id, $a->id]);
});

it('S04: an agent with zero capacity never receives leads', function (): void {
    User::factory()->agent()->capacity(0)->create();
    $a = User::factory()->agent()->create();

    expect(array_unique(createLeads(3)))->toBe([$a->id]);
});

it('S05: the rotation survives when the cursor points at a deactivated agent', function (): void {
    [$a, $b, $c] = User::factory()->agent()->count(3)->create()->all();
    AssignmentCursor::query()->where('pool', 'global')->update(['last_user_id' => $b->id]);
    $b->update(['is_active' => false]);

    expect(createLeads(2))->toBe([$c->id, $a->id]);
});

it('S06: a duplicate external lead does not consume capacity twice', function (): void {
    $agent = User::factory()->agent()->capacity(5)->create();

    $first = createLead(['source' => LeadSource::Facebook, 'external_ref' => 'fb-1']);
    $second = createLead(['source' => LeadSource::Facebook, 'external_ref' => 'fb-1']);

    expect($second->id)->toBe($first->id)
        ->and($second->wasRecentlyCreated)->toBeFalse()
        ->and(Lead::query()->count())->toBe(1)
        ->and(scenarioLedger($agent))->toBe(1);
});

// ---------------------------------------------------------- Bulk assignment

it('S07: the API rejects duplicate lead ids in a bulk request', function (): void {
    $agent = User::factory()->agent()->create();
    $lead = Lead::factory()->create();
    Sanctum::actingAs(User::factory()->admin()->create());

    $this->postJson('/api/v1/leads/bulk-assign', ['lead_ids' => [$lead->id, $lead->id], 'assignee_id' => $agent->id])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed');
});

it('S08: a bulk request exactly at the remaining capacity succeeds', function (): void {
    $admin = User::factory()->admin()->create();
    $agent = User::factory()->agent()->capacity(3)->create();

    expect(scenarioBulk(Lead::factory()->count(3)->create()->modelKeys(), $agent, $admin))->toBe(3)
        ->and(scenarioLedger($agent))->toBe(3);
});

it('S09: reassigning charges the new agent and does not refund the old one (A-11)', function (): void {
    $admin = User::factory()->admin()->create();
    [$a, $b] = User::factory()->agent()->count(2)->create()->all();
    $lead = Lead::factory()->create();

    scenarioBulk([$lead->id], $a, $admin);
    scenarioBulk([$lead->id], $b, $admin);

    expect($lead->fresh()->assigned_to)->toBe($b->id)
        ->and(scenarioLedger($a))->toBe(1)
        ->and(scenarioLedger($b))->toBe(1);
});

it('S10: a bulk request containing a non-existent lead changes nothing', function (): void {
    $admin = User::factory()->admin()->create();
    $agent = User::factory()->agent()->create();
    $lead = Lead::factory()->create();

    expect(fn () => scenarioBulk([$lead->id, 999_999], $agent, $admin))->toThrow(LeadsNotAccessible::class)
        ->and($lead->fresh()->assigned_to)->toBeNull()
        ->and(scenarioLedger($agent))->toBe(0);
});

it('S11: an inactive admin cannot bulk assign', function (): void {
    $admin = User::factory()->admin()->inactive()->create();
    $agent = User::factory()->agent()->create();

    expect(fn () => scenarioBulk([Lead::factory()->create()->id], $agent, $admin))
        ->toThrow(AuthorizationException::class);
});

// ------------------------------------------------------------ Status & audit

it('S12: a chain of status changes produces a consistent, ordered history', function (): void {
    $agent = User::factory()->agent()->create();
    $lead = createLead();
    $change = app(ChangeLeadStatus::class);

    foreach ([LeadStatus::Contacted, LeadStatus::Qualified, LeadStatus::Won] as $i => $status) {
        $this->travel(1)->minutes();
        $change->handle($lead, $status, $agent);
    }

    $history = LeadStatusLog::query()->where('lead_id', $lead->id)->orderBy('changed_at')->orderBy('id')->get();
    expect($history->pluck('new_status')->map->value->all())->toBe(['new', 'contacted', 'qualified', 'won']);

    // Each entry's old status is the previous entry's new status.
    foreach ($history->skip(1)->values() as $i => $entry) {
        expect($entry->old_status)->toBe($history[$i]->new_status);
    }
});

it('S13: editing fields and status together writes exactly one status audit entry', function (): void {
    $agent = User::factory()->agent()->create();
    $lead = createLead();

    app(UpdateLead::class)->handle($lead, UpdateLeadData::fromPartial($lead, ['name' => 'Renamed', 'status' => 'contacted']), $agent);

    expect($lead->fresh()->name)->toBe('Renamed')
        ->and(LeadStatusLog::query()->where('lead_id', $lead->id)->whereNotNull('old_status')->count())->toBe(1);
});

it('S14: an agent cannot change the status of an unassigned lead through the API', function (): void {
    $lead = Lead::factory()->create();
    Sanctum::actingAs(User::factory()->agent()->create());

    $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'won'])->assertNotFound();
    expect($lead->fresh()->status)->toBe(LeadStatus::New);
});

it('S15: an invalid status is rejected and leaves no audit entry', function (): void {
    $agent = User::factory()->agent()->create();
    $lead = Lead::factory()->assignedTo($agent)->create();
    Sanctum::actingAs($agent);

    $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'archived'])->assertUnprocessable();
    expect(LeadStatusLog::query()->where('lead_id', $lead->id)->whereNotNull('old_status')->count())->toBe(0);
});

it('S16: a manager\'s status change is attributed to the manager', function (): void {
    $manager = User::factory()->manager()->create();
    $team = Team::factory()->managedBy($manager)->create();
    $agent = User::factory()->agent()->inTeam($team)->create();
    $lead = Lead::factory()->assignedTo($agent)->create();

    app(ChangeLeadStatus::class)->handle($lead, LeadStatus::Qualified, $manager);

    expect(LeadStatusLog::query()->where('lead_id', $lead->id)->where('new_status', 'qualified')->sole()->user_id)->toBe($manager->id);
});

// ---------------------------------------------------------------- Escalation

it('S17: a lead that went back to New is escalated 30 minutes after it re-entered New, not after creation', function (): void {
    $agent = User::factory()->agent()->create();
    $lead = createLead();
    $change = app(ChangeLeadStatus::class);

    $this->travel(20)->minutes();
    $change->handle($lead, LeadStatus::Contacted, $agent);
    $this->travel(1)->minutes();
    $change->handle($lead, LeadStatus::New, $agent);    // back to New at T+21

    $this->travel(10)->minutes();                       // T+31: only 10 minutes in New
    expect(scenarioEscalate($lead->id))->toBeFalse();

    $this->travel(21)->minutes();                       // T+52: 31 minutes in New
    expect(scenarioEscalate($lead->id))->toBeTrue();
});

it('S18: the escalation goes to the manager of the agent\'s current team', function (): void {
    $oldManager = User::factory()->manager()->create();
    $newManager = User::factory()->manager()->create();
    $oldTeam = Team::factory()->managedBy($oldManager)->create();
    $newTeam = Team::factory()->managedBy($newManager)->create();
    $agent = User::factory()->agent()->inTeam($oldTeam)->create();
    $lead = createLead();

    $agent->update(['team_id' => $newTeam->id]);
    $this->travel(31)->minutes();
    scenarioEscalate($lead->id);

    expect(LeadEscalation::query()->sole()->manager_id)->toBe($newManager->id)
        ->and($oldManager->notifications()->count())->toBe(0);
});

it('S19: an inactive manager is skipped and the admins are notified instead', function (): void {
    $manager = User::factory()->manager()->inactive()->create();
    $team = Team::factory()->managedBy($manager)->create();
    User::factory()->agent()->inTeam($team)->create();
    $admin = User::factory()->admin()->create();
    $lead = createLead();

    $this->travel(31)->minutes();
    scenarioEscalate($lead->id);

    expect(LeadEscalation::query()->sole()->manager_id)->toBeNull()
        ->and($admin->notifications()->count())->toBe(1)
        ->and($manager->notifications()->count())->toBe(0);
});

it('S20: the sweeper also escalates unassigned leads', function (): void {
    User::factory()->admin()->create();
    Bus::fake();
    $lead = Lead::factory()->create(); // unassigned, status new
    $lead->forceFill(['created_at' => now()->subHour()])->saveQuietly();

    Artisan::call('leads:escalate-stale');

    Bus::assertDispatched(EscalateStaleLead::class, fn (EscalateStaleLead $job): bool => $job->leadId === $lead->id);
});

it('S21: a lead is escalated at most once in its life, even if it returns to New', function (): void {
    $agent = User::factory()->agent()->create();
    $lead = createLead();
    $this->travel(31)->minutes();
    scenarioEscalate($lead->id);

    app(ChangeLeadStatus::class)->handle($lead, LeadStatus::Contacted, $agent);
    app(ChangeLeadStatus::class)->handle($lead, LeadStatus::New, $agent);
    $this->travel(40)->minutes();

    expect(scenarioEscalate($lead->id))->toBeFalse()
        ->and(LeadEscalation::query()->count())->toBe(1);
});

// ------------------------------------------------------ Authorization & API

it('S22: a manager loses API access to a lead when its agent leaves the team', function (): void {
    $manager = User::factory()->manager()->create();
    $team = Team::factory()->managedBy($manager)->create();
    $agent = User::factory()->agent()->inTeam($team)->create();
    $lead = Lead::factory()->assignedTo($agent)->create();
    Sanctum::actingAs($manager);

    $this->getJson("/api/v1/leads/{$lead->id}")->assertOk();
    $agent->update(['team_id' => null]);
    $this->getJson("/api/v1/leads/{$lead->id}")->assertNotFound();
});

it('S23: a revoked token stops working immediately', function (): void {
    $agent = User::factory()->agent()->create();
    $token = $agent->createToken('t')->plainTextToken;

    $this->withToken($token)->deleteJson('/api/v1/auth/tokens/current')->assertNoContent();
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
});

it('S24: agents cannot reach administration screens or webhook settings', function (): void {
    $agent = User::factory()->agent()->create();

    $this->actingAs($agent)->get('/admin/users')->assertForbidden();
    $this->actingAs($agent)->get('/admin/teams')->assertForbidden();
    $this->actingAs($agent)->get('/admin/webhook-subscriptions')->assertForbidden();

    Sanctum::actingAs($agent);
    $this->getJson('/api/v1/webhook-subscriptions')->assertForbidden();
});

it('S25: combined API filters return the intersection', function (): void {
    $agent = User::factory()->agent()->create();
    $match = Lead::factory()->assignedTo($agent)->overdue()->highPriority()->create();
    Lead::factory()->assignedTo($agent)->overdue()->create(['priority' => LeadPriority::Low]);
    Lead::factory()->assignedTo($agent)->highPriority()->create(['next_follow_up_at' => now()->addDay()]);
    Lead::factory()->overdue()->highPriority()->create(); // not mine
    Sanctum::actingAs($agent);

    $ids = $this->getJson('/api/v1/leads?filter[mine]=1&filter[overdue]=1&filter[priority]=high')->json('data.*.id');

    expect($ids)->toBe([$match->id]);
});

it('S26: cursor pagination returns every lead exactly once', function (): void {
    $agent = User::factory()->agent()->create();
    $all = Lead::factory()->count(60)->assignedTo($agent)->create()->modelKeys();
    Sanctum::actingAs($agent);

    $seen = [];
    $cursor = null;
    $pages = 0;
    do {
        $page = $this->getJson('/api/v1/leads?per_page=25'.($cursor ? '&cursor='.$cursor : ''))->assertOk();
        $seen = [...$seen, ...$page->json('data.*.id')];
        $cursor = $page->json('meta.next_cursor');
        $pages++;
    } while ($cursor !== null && $pages < 10);

    expect($pages)->toBe(3)
        ->and(count($seen))->toBe(60)
        ->and(array_unique($seen))->toEqualCanonicalizing($all);
});

// ---------------------------------------------------------------- Webhooks

it('S27: the same external id from two different sources creates two leads', function (): void {
    config(['webhooks.inbound.sources' => ['facebook' => 'fb-secret-123', 'website' => 'web-secret-123']]);
    User::factory()->agent()->create();
    $payload = ['external_id' => 'shared-7', 'name' => 'Twin Lead', 'phone' => '+201000000777'];

    scenarioInbound($payload, 'facebook', 'fb-secret-123')->assertCreated();
    scenarioInbound($payload, 'website', 'web-secret-123')->assertCreated();

    expect(Lead::query()->where('external_ref', 'shared-7')->pluck('source')->map->value->sort()->values()->all())
        ->toBe(['facebook', 'website']);
});

it('S28: a request timestamped too far in the future is rejected', function (): void {
    config(['webhooks.inbound.sources' => ['facebook' => 'fb-secret-123']]);

    scenarioInbound(['external_id' => 'f-1', 'name' => 'Future', 'phone' => '+201000000888'], 'facebook', 'fb-secret-123', now()->addMinutes(10)->getTimestamp())
        ->assertUnauthorized();

    expect(Lead::query()->count())->toBe(0);
});

it('S29: inactive or unrelated subscriptions receive nothing', function (): void {
    Http::fake();
    app()->instance(OutboundUrlGuard::class, new OutboundUrlGuard(false, fn (): array => ['93.184.216.34']));
    User::factory()->agent()->create();
    WebhookSubscription::query()->create(['name' => 'Off', 'url' => 'https://a.example.com', 'secret' => 'secret-secret-123',
        'events' => [WebhookEvent::LeadCreated->value], 'is_active' => false]);
    WebhookSubscription::query()->create(['name' => 'Other event', 'url' => 'https://b.example.com', 'secret' => 'secret-secret-123',
        'events' => [WebhookEvent::LeadEscalated->value], 'is_active' => true]);

    createLead();

    Http::assertNothingSent();
});

// ---------------------------------------------------------------- Dashboard

it('S30: "new today" follows the Cairo business day, not UTC', function (): void {
    config(['leads.business_timezone' => 'Africa/Cairo']);
    $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00', 'Africa/Cairo'));
    $admin = User::factory()->admin()->create();

    $yesterday = Lead::factory()->create();
    $yesterday->forceFill(['created_at' => CarbonImmutable::parse('2026-11-22 23:30', 'Africa/Cairo')->utc()])->saveQuietly();
    $today = Lead::factory()->create();
    $today->forceFill(['created_at' => CarbonImmutable::parse('2026-11-23 00:10', 'Africa/Cairo')->utc()])->saveQuietly();

    expect(app(LeadDashboardStats::class)->summary($admin)['new_today'])->toBe(1);
});
