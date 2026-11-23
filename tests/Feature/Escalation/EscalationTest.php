<?php

declare(strict_types=1);

use App\Actions\Leads\ChangeLeadStatus;
use App\Actions\Leads\EscalateLead;
use App\Enums\LeadStatus;
use App\Jobs\EscalateStaleLead;
use App\Models\LeadEscalation;
use App\Models\Team;
use App\Models\User;
use App\Notifications\LeadEscalatedNotification;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->manager = User::factory()->manager()->create();
    $this->team = Team::factory()->managedBy($this->manager)->create();
    $this->agent = User::factory()->agent()->inTeam($this->team)->create();
});

/**
 * Simulate a lost delayed job (queue flushed): drop its ShouldBeUnique lock.
 */
function releaseEscalationLock(int $leadId): void
{
    (new UniqueLock(Cache::driver()))->release(new EscalateStaleLead($leadId));
}

function runEscalationJob(int $leadId): void
{
    (new EscalateStaleLead($leadId))->handle(app(EscalateLead::class));
}

it('schedules the escalation job 30 minutes after creation', function (): void {
    Bus::fake();
    $this->freezeSecond();

    $lead = createLead();

    Bus::assertDispatched(EscalateStaleLead::class, fn (EscalateStaleLead $job): bool => $job->leadId === $lead->id
        && $job->delay->equalTo(now()->addMinutes(30))
        && $job->queue === 'escalations');
});

it('escalates a lead still new after 30 minutes to the team manager', function (): void {
    $lead = createLead();
    $this->travel(31)->minutes();

    runEscalationJob($lead->id);

    $escalation = LeadEscalation::query()->sole();
    expect($escalation->lead_id)->toBe($lead->id)
        ->and($escalation->manager_id)->toBe($this->manager->id)
        ->and($this->manager->notifications()->count())->toBe(1)
        ->and($this->manager->notifications()->first()->type)->toBe(LeadEscalatedNotification::class);
});

it('does nothing if the status changed before the job runs', function (): void {
    $lead = createLead();
    app(ChangeLeadStatus::class)->handle($lead, LeadStatus::Contacted, $this->agent);
    $this->travel(31)->minutes();

    runEscalationJob($lead->id);

    expect(LeadEscalation::query()->count())->toBe(0)
        ->and(DB::table('notifications')->count())->toBe(0)
        ->and($lead->fresh()->status)->toBe(LeadStatus::Contacted);
});

it('creates no duplicate escalation or notification when run twice', function (): void {
    $lead = createLead();
    $this->travel(31)->minutes();

    runEscalationJob($lead->id);
    runEscalationJob($lead->id);

    expect(LeadEscalation::query()->count())->toBe(1)
        ->and(DB::table('notifications')->count())->toBe(1);
});

it('never changes the lead when escalating', function (): void {
    $lead = createLead();
    $before = $lead->fresh()->only(['status', 'assigned_to', 'assigned_at', 'next_follow_up_at']);
    $this->travel(31)->minutes();

    runEscalationJob($lead->id);

    expect($lead->fresh()->only(['status', 'assigned_to', 'assigned_at', 'next_follow_up_at']))->toEqual($before);
});

it('does nothing when it runs before the delay has passed', function (): void {
    $lead = createLead();
    $this->travel(10)->minutes();

    runEscalationJob($lead->id);

    expect(LeadEscalation::query()->count())->toBe(0);
});

it('does nothing for a deleted lead', function (): void {
    $lead = createLead();
    $lead->delete();
    $this->travel(31)->minutes();

    runEscalationJob($lead->id);

    expect(LeadEscalation::query()->count())->toBe(0);
});

it('falls back to active admins when the lead is unassigned', function (): void {
    $this->agent->update(['is_active' => false]);
    $admins = User::factory()->admin()->count(2)->create();
    User::factory()->admin()->inactive()->create();
    $lead = createLead();
    $this->travel(31)->minutes();

    runEscalationJob($lead->id);

    expect(LeadEscalation::query()->sole()->manager_id)->toBeNull()
        ->and(DB::table('notifications')->pluck('notifiable_id')->map(fn ($id) => (int) $id)->all())
        ->toEqualCanonicalizing($admins->modelKeys());
});

it('rolls back the escalation if the notification fails, so a retry starts clean', function (): void {
    $lead = createLead();
    $this->travel(31)->minutes();
    Notification::shouldReceive('sendNow')->once()->andThrow(new RuntimeException('mail server down'));

    expect(fn () => runEscalationJob($lead->id))->toThrow(RuntimeException::class);
    expect(LeadEscalation::query()->count())->toBe(0);
});

it('skips a sweeper dispatch while the original delayed job is still queued', function (): void {
    Bus::fake();
    createLead();
    $this->travel(31)->minutes();

    Artisan::call('leads:escalate-stale');

    // Only the original dispatch from createLead; the unique lock blocked the copy.
    Bus::assertDispatchedTimes(EscalateStaleLead::class, 1);
});

it('sweeper dispatches jobs only for due, un-escalated new leads', function (): void {
    Bus::fake();
    $due = createLead();
    $contacted = createLead();
    $contacted->update(['status' => LeadStatus::Contacted]);
    $this->travel(31)->minutes();
    $recent = createLead();
    $escalated = createLead();
    $escalated->forceFill(['created_at' => now()->subHour()])->saveQuietly();
    LeadEscalation::query()->create(['lead_id' => $escalated->id, 'escalated_at' => now()]);
    Bus::fake(); // reset the jobs dispatched by createLead
    // The original delayed jobs were lost; otherwise their unique locks
    // would (correctly) block the sweeper's copies.
    foreach ([$due, $contacted, $recent, $escalated] as $lead) {
        releaseEscalationLock($lead->id);
    }

    Artisan::call('leads:escalate-stale');

    Bus::assertDispatchedTimes(EscalateStaleLead::class, 1);
    Bus::assertDispatched(EscalateStaleLead::class, fn ($job) => $job->leadId === $due->id);
});

it('stays idempotent when the delayed job and the sweeper both run', function (): void {
    $lead = createLead();
    $this->travel(31)->minutes();

    runEscalationJob($lead->id);
    // Bypass ShouldBeUnique so the second run really executes: this proves the
    // database guarantee alone (queue is sync in tests, so the sweeper runs it).
    releaseEscalationLock($lead->id);
    Artisan::call('leads:escalate-stale');
    runEscalationJob($lead->id);

    expect(LeadEscalation::query()->count())->toBe(1)
        ->and(DB::table('notifications')->count())->toBe(1);
});
