<?php

declare(strict_types=1);

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadStatusLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->manager = User::factory()->manager()->create();
    $this->team = Team::factory()->managedBy($this->manager)->create();
    $this->agent = User::factory()->agent()->inTeam($this->team)->capacity(3)->create();
    $this->otherAgent = User::factory()->agent()->capacity(3)->create();
});

describe('authentication', function (): void {
    it('issues a token for valid credentials and rejects bad ones', function (): void {
        $this->postJson('/api/v1/auth/tokens', ['email' => $this->agent->email, 'password' => 'password'])
            ->assertCreated()
            ->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'name', 'role']]);

        $this->postJson('/api/v1/auth/tokens', ['email' => $this->agent->email, 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed');
    });

    it('refuses tokens for inactive users and blocks their existing tokens', function (): void {
        $token = $this->agent->createToken('t')->plainTextToken;
        $this->agent->update(['is_active' => false]);

        $this->postJson('/api/v1/auth/tokens', ['email' => $this->agent->email, 'password' => 'password'])
            ->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/leads')->assertForbidden()->assertJsonPath('code', 'forbidden');
    });

    it('requires a token', function (): void {
        $this->getJson('/api/v1/leads')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    });

    it('rate limits the token endpoint', function (): void {
        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/auth/tokens', ['email' => 'x@example.com', 'password' => 'nope']);
        }

        $this->postJson('/api/v1/auth/tokens', ['email' => 'x@example.com', 'password' => 'nope'])->assertTooManyRequests();
    });
});

describe('reading leads', function (): void {
    it('lists only visible leads with cursor pagination', function (): void {
        $own = Lead::factory()->count(3)->assignedTo($this->agent)->create();
        Lead::factory()->assignedTo($this->otherAgent)->create();
        Sanctum::actingAs($this->agent);

        $response = $this->getJson('/api/v1/leads?per_page=2')->assertOk();

        expect($response->json('data.*.id'))->toBe([$own[2]->id, $own[1]->id])
            ->and($response->json('meta.next_cursor'))->not->toBeNull();

        $next = $this->getJson('/api/v1/leads?per_page=2&cursor='.$response->json('meta.next_cursor'))->assertOk();
        expect($next->json('data.*.id'))->toBe([$own[0]->id]);
    });

    it('applies the same filters as the admin panel', function (): void {
        $overdue = Lead::factory()->assignedTo($this->agent)->overdue()->create();
        $high = Lead::factory()->assignedTo($this->agent)->highPriority()->create(['next_follow_up_at' => now()->addDay()]);
        $unassigned = Lead::factory()->create();
        Sanctum::actingAs($this->admin);

        expect($this->getJson('/api/v1/leads?filter[overdue]=1')->json('data.*.id'))->toBe([$overdue->id])
            ->and($this->getJson('/api/v1/leads?filter[priority]=high')->json('data.*.id'))->toContain($high->id)
            ->and($this->getJson('/api/v1/leads?filter[unassigned]=1')->json('data.*.id'))->toBe([$unassigned->id]);
    });

    it('returns 404 for a lead the caller cannot see', function (): void {
        $foreign = Lead::factory()->assignedTo($this->otherAgent)->create();
        Sanctum::actingAs($this->agent);

        $this->getJson("/api/v1/leads/{$foreign->id}")->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->patchJson("/api/v1/leads/{$foreign->id}/status", ['status' => 'won'])->assertNotFound();
        $this->getJson("/api/v1/leads/{$foreign->id}/status-history")->assertNotFound();
        expect($foreign->fresh()->status)->toBe(LeadStatus::New);
    });

    it('loads the list with a constant number of queries', function (): void {
        Sanctum::actingAs($this->admin);
        Lead::factory()->count(2)->assignedTo($this->agent)->create();

        DB::enableQueryLog();
        $this->getJson('/api/v1/leads')->assertOk();
        $few = count(DB::getQueryLog());
        DB::flushQueryLog();

        Lead::factory()->count(20)->assignedTo($this->otherAgent)->create();
        DB::flushQueryLog();
        $this->getJson('/api/v1/leads')->assertOk();

        expect(count(DB::getQueryLog()))->toBe($few);
    });
});

describe('writing leads', function (): void {
    it('creates a lead with automatic assignment', function (): void {
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/v1/leads', ['name' => 'API Lead', 'phone' => '+20 100 000 1111', 'source' => 'website', 'priority' => 3])
            ->assertCreated()
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.phone', '+201000001111')
            ->assertJsonPath('data.priority_label', 'High')
            ->assertJsonStructure(['data' => ['assigned_user' => ['id', 'name']]]);
    });

    it('does not let agents create leads', function (): void {
        Sanctum::actingAs($this->agent);

        $this->postJson('/api/v1/leads', ['name' => 'x', 'phone' => '+201000001111', 'source' => 'website'])->assertForbidden();
    });

    it('validates input with a consistent error envelope', function (): void {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/leads', ['name' => '', 'source' => 'tv'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['name', 'phone', 'source']);
    });

    it('changes status and records the audit entry', function (): void {
        $lead = Lead::factory()->assignedTo($this->agent)->create();
        Sanctum::actingAs($this->agent);

        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'contacted'])
            ->assertOk()->assertJsonPath('data.status', 'contacted');

        $this->getJson("/api/v1/leads/{$lead->id}/status-history")
            ->assertOk()
            ->assertJsonPath('data.0.old_status', 'new')
            ->assertJsonPath('data.0.new_status', 'contacted')
            ->assertJsonPath('data.0.changed_by.id', $this->agent->id);

        // One "created" entry plus exactly one change entry.
        expect(LeadStatusLog::query()->where('lead_id', $lead->id)->whereNotNull('old_status')->count())->toBe(1);
    });

    it('partially updates a lead', function (): void {
        $lead = Lead::factory()->assignedTo($this->agent)->create(['name' => 'Old']);
        Sanctum::actingAs($this->agent);

        $this->patchJson("/api/v1/leads/{$lead->id}", ['name' => 'New name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New name')
            ->assertJsonPath('data.phone', $lead->phone);
    });
});

describe('bulk assignment', function (): void {
    it('assigns leads and answers 409 when capacity is exceeded', function (): void {
        $leads = Lead::factory()->count(4)->create();
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/leads/bulk-assign', ['lead_ids' => $leads->take(2)->modelKeys(), 'assignee_id' => $this->agent->id])
            ->assertOk()->assertJson(['assigned' => 2]);

        $this->postJson('/api/v1/leads/bulk-assign', ['lead_ids' => $leads->skip(2)->modelKeys(), 'assignee_id' => $this->agent->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'capacity_exceeded')
            ->assertJsonPath('context.remaining', 1);
    });

    it('forbids agents and out-of-team targets', function (): void {
        $lead = Lead::factory()->assignedTo($this->agent)->create();

        Sanctum::actingAs($this->agent);
        $this->postJson('/api/v1/leads/bulk-assign', ['lead_ids' => [$lead->id], 'assignee_id' => $this->agent->id])->assertForbidden();

        Sanctum::actingAs($this->manager);
        $this->postJson('/api/v1/leads/bulk-assign', ['lead_ids' => [$lead->id], 'assignee_id' => $this->otherAgent->id])
            ->assertForbidden()->assertJsonPath('code', 'assignee_not_eligible');
    });
});

it('stores follow-up times sent with a timezone offset as the correct UTC instant', function (): void {
    Sanctum::actingAs(User::factory()->admin()->create());

    $id = $this->postJson('/api/v1/leads', [
        'name' => 'Offset Lead', 'phone' => '+201000000555', 'source' => 'website',
        'next_follow_up_at' => '2026-12-01T10:00:00+02:00',
    ])->assertCreated()->json('data.id');

    expect(Lead::query()->findOrFail($id)->next_follow_up_at->utc()->toIso8601String())->toBe('2026-12-01T08:00:00+00:00');

    $this->patchJson("/api/v1/leads/{$id}", ['next_follow_up_at' => '2026-12-02T09:30:00-05:00'])->assertOk();
    expect(Lead::query()->findOrFail($id)->next_follow_up_at->utc()->toIso8601String())->toBe('2026-12-02T14:30:00+00:00');
});
