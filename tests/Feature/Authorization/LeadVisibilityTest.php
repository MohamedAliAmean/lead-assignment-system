<?php

declare(strict_types=1);

use App\Authorization\LeadVisibility;
use App\Models\Builders\LeadBuilder;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;

function visibleLeads(User $user): LeadBuilder
{
    return app(LeadVisibility::class)->apply(Lead::query(), $user);
}

beforeEach(function (): void {
    $this->manager = User::factory()->manager()->create();
    $this->team = Team::factory()->managedBy($this->manager)->create();
    $this->agent = User::factory()->agent()->inTeam($this->team)->create();
    $this->teammate = User::factory()->agent()->inTeam($this->team)->create();
    $this->outsider = User::factory()->agent()->create();

    $this->ownLead = Lead::factory()->assignedTo($this->agent)->create();
    $this->teammateLead = Lead::factory()->assignedTo($this->teammate)->create();
    $this->outsiderLead = Lead::factory()->assignedTo($this->outsider)->create();
    $this->unassignedLead = Lead::factory()->create();
});

it('lets an agent see only their own leads', function (): void {
    expect(visibleLeads($this->agent)->pluck('id')->all())->toBe([$this->ownLead->id])
        ->and($this->agent->can('view', $this->ownLead))->toBeTrue()
        ->and($this->agent->can('view', $this->teammateLead))->toBeFalse()
        ->and($this->agent->can('update', $this->outsiderLead))->toBeFalse()
        ->and($this->agent->can('changeStatus', $this->unassignedLead))->toBeFalse();
});

it('lets a manager see only leads of their team members', function (): void {
    expect(visibleLeads($this->manager)->pluck('id')->all())
        ->toEqualCanonicalizing([$this->ownLead->id, $this->teammateLead->id])
        ->and($this->manager->can('view', $this->teammateLead))->toBeTrue()
        ->and($this->manager->can('view', $this->outsiderLead))->toBeFalse()
        ->and($this->manager->can('view', $this->unassignedLead))->toBeFalse();
});

it('lets an admin see every lead', function (): void {
    $admin = User::factory()->admin()->create();

    expect(visibleLeads($admin)->count())->toBe(4)
        ->and($admin->can('view', $this->outsiderLead))->toBeTrue()
        ->and($admin->can('delete', $this->outsiderLead))->toBeTrue();
});

it('moves visibility with the agent when they change team (A-9)', function (): void {
    $otherManager = User::factory()->manager()->create();
    $otherTeam = Team::factory()->managedBy($otherManager)->create();

    $this->agent->update(['team_id' => $otherTeam->id]);

    expect($this->manager->can('view', $this->ownLead))->toBeFalse()
        ->and($otherManager->can('view', $this->ownLead))->toBeTrue()
        ->and(visibleLeads($otherManager)->pluck('id')->all())->toBe([$this->ownLead->id]);
});

it('fails closed for inactive users', function (): void {
    $this->agent->update(['is_active' => false]);

    expect(visibleLeads($this->agent)->count())->toBe(0)
        ->and($this->agent->can('view', $this->ownLead))->toBeFalse();
});

it('shows nothing to a manager who has no team', function (): void {
    $loneManager = User::factory()->manager()->create();

    expect(visibleLeads($loneManager)->count())->toBe(0);
});

it('only lets admins and managers create, and only admins delete', function (): void {
    expect($this->agent->can('create', Lead::class))->toBeFalse()
        ->and($this->manager->can('create', Lead::class))->toBeTrue()
        ->and($this->manager->can('delete', $this->ownLead))->toBeFalse();
});
