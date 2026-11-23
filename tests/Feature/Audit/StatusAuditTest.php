<?php

declare(strict_types=1);

use App\Actions\Leads\ChangeLeadStatus;
use App\Enums\LeadStatus;
use App\Models\LeadStatusLog;
use App\Models\User;

it('logs the initial status when a lead is created', function (): void {
    $admin = User::factory()->admin()->create();

    $lead = createLead(actor: $admin);

    $log = LeadStatusLog::query()->where('lead_id', $lead->id)->sole();
    expect($log->old_status)->toBeNull()
        ->and($log->new_status)->toBe(LeadStatus::New)
        ->and($log->user_id)->toBe($admin->id);
});

it('writes exactly one audit row with the right values on a status change', function (): void {
    $agent = User::factory()->agent()->create();
    $lead = createLead();

    app(ChangeLeadStatus::class)->handle($lead, LeadStatus::Contacted, $agent);

    $log = LeadStatusLog::query()->where('lead_id', $lead->id)->whereNotNull('old_status')->sole();
    expect($log->old_status)->toBe(LeadStatus::New)
        ->and($log->new_status)->toBe(LeadStatus::Contacted)
        ->and($log->user_id)->toBe($agent->id)
        ->and($log->changed_at)->not->toBeNull();
});

it('does not write an audit row when the status is unchanged', function (): void {
    $agent = User::factory()->agent()->create();
    $lead = createLead();

    app(ChangeLeadStatus::class)->handle($lead, LeadStatus::New, $agent);

    expect(LeadStatusLog::query()->where('lead_id', $lead->id)->count())->toBe(1);
});

it('does not log edits that leave the status alone', function (): void {
    User::factory()->agent()->create();
    $lead = createLead();

    $lead->update(['name' => 'Renamed']);

    expect(LeadStatusLog::query()->where('lead_id', $lead->id)->count())->toBe(1);
});

it('records a null user for system status changes', function (): void {
    User::factory()->agent()->create();
    $lead = createLead();

    $lead->update(['status' => LeadStatus::Lost]);

    $log = LeadStatusLog::query()->where('lead_id', $lead->id)->where('new_status', 'lost')->sole();
    expect($log->user_id)->toBeNull();
});
