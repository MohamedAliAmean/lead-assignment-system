<?php

declare(strict_types=1);

use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Models\Lead;
use App\Models\LeadStatusLog;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->manager = User::factory()->manager()->create();
    $this->team = Team::factory()->managedBy($this->manager)->create();
    $this->agent = User::factory()->agent()->inTeam($this->team)->capacity(3)->create();
    $this->otherAgent = User::factory()->agent()->capacity(3)->create();
});

describe('visibility in the admin panel', function (): void {
    it('lists only the agent\'s own leads', function (): void {
        $own = Lead::factory()->count(2)->assignedTo($this->agent)->create();
        $foreign = Lead::factory()->assignedTo($this->otherAgent)->create();

        $this->actingAs($this->agent);

        Livewire::test(ListLeads::class)
            ->assertCanSeeTableRecords($own)
            ->assertCanNotSeeTableRecords([$foreign]);
    });

    it('blocks direct URLs to another agent\'s lead', function (): void {
        $own = Lead::factory()->assignedTo($this->agent)->create();
        $foreign = Lead::factory()->assignedTo($this->otherAgent)->create();

        $this->actingAs($this->agent);

        $this->get(LeadResource::getUrl('view', ['record' => $own]))->assertOk();
        $this->get(LeadResource::getUrl('view', ['record' => $foreign]))->assertNotFound();
        $this->get(LeadResource::getUrl('edit', ['record' => $foreign]))->assertNotFound();
    });

    it('lets a manager open team leads but not others', function (): void {
        $teamLead = Lead::factory()->assignedTo($this->agent)->create();
        $foreign = Lead::factory()->assignedTo($this->otherAgent)->create();

        $this->actingAs($this->manager);

        $this->get(LeadResource::getUrl('view', ['record' => $teamLead]))->assertOk();
        $this->get(LeadResource::getUrl('view', ['record' => $foreign]))->assertNotFound();
    });

    it('keeps inactive users out of the panel', function (): void {
        $this->agent->update(['is_active' => false]);

        $this->actingAs($this->agent)->get(LeadResource::getUrl('index'))->assertForbidden();
    });
});

describe('filters', function (): void {
    it('filters My Leads, Overdue and High Priority', function (): void {
        $mine = Lead::factory()->assignedTo($this->agent)->create(['priority' => LeadPriority::Low]);
        $overdue = Lead::factory()->assignedTo($this->agent)->overdue()->create(['priority' => LeadPriority::Low]);
        $overdueButClosed = Lead::factory()->assignedTo($this->agent)->overdue()->status(LeadStatus::Won)->create(['priority' => LeadPriority::Low]);
        $high = Lead::factory()->assignedTo($this->otherAgent)->highPriority()->create();

        $this->actingAs($this->admin);

        Livewire::test(ListLeads::class)
            ->filterTable('overdue')
            ->assertCanSeeTableRecords([$overdue])
            ->assertCanNotSeeTableRecords([$mine, $overdueButClosed, $high]);

        Livewire::test(ListLeads::class)
            ->filterTable('high_priority')
            ->assertCanSeeTableRecords([$high])
            ->assertCanNotSeeTableRecords([$mine, $overdue]);

        $this->actingAs($this->agent);
        Livewire::test(ListLeads::class)
            ->filterTable('my_leads')
            ->assertCanSeeTableRecords([$mine, $overdue])
            ->assertCanNotSeeTableRecords([$high]);
    });

    it('shows the Unassigned filter to admins only', function (): void {
        $unassigned = Lead::factory()->create();
        $assigned = Lead::factory()->assignedTo($this->agent)->create();

        $this->actingAs($this->admin);
        Livewire::test(ListLeads::class)
            ->filterTable('unassigned')
            ->assertCanSeeTableRecords([$unassigned])
            ->assertCanNotSeeTableRecords([$assigned]);

        $this->actingAs($this->agent);
        Livewire::test(ListLeads::class)->assertTableFilterHidden('unassigned');
    });
});

describe('bulk assignment', function (): void {
    it('assigns the selected leads through the application layer', function (): void {
        $leads = Lead::factory()->count(2)->create();

        $this->actingAs($this->admin);

        Livewire::test(ListLeads::class)
            ->callTableBulkAction('assign', $leads, data: ['assignee_id' => $this->agent->id])
            ->assertNotified('2 lead(s) assigned');

        expect(Lead::query()->where('assigned_to', $this->agent->id)->count())->toBe(2);
    });

    it('refuses the whole selection when it exceeds daily capacity', function (): void {
        $leads = Lead::factory()->count(4)->create(); // capacity is 3

        $this->actingAs($this->admin);

        Livewire::test(ListLeads::class)
            ->callTableBulkAction('assign', $leads, data: ['assignee_id' => $this->agent->id])
            ->assertNotified('Nothing was assigned');

        expect(Lead::query()->whereNotNull('assigned_to')->count())->toBe(0);
    });

    it('refuses a manager assigning outside their team even if the UI is bypassed', function (): void {
        $lead = Lead::factory()->assignedTo($this->agent)->create();

        $this->actingAs($this->manager);

        Livewire::test(ListLeads::class)
            ->callTableBulkAction('assign', [$lead], data: ['assignee_id' => $this->otherAgent->id]);

        expect($lead->fresh()->assigned_to)->toBe($this->agent->id);
    });

    it('hides the bulk assign action from agents', function (): void {
        $this->actingAs($this->agent);

        Livewire::test(ListLeads::class)->assertTableBulkActionHidden('assign');
    });
});

describe('create, edit and status change', function (): void {
    it('creates leads through Round Robin from the create page', function (): void {
        $this->actingAs($this->admin);

        Livewire::test(CreateLead::class)
            ->fillForm([
                'name' => 'Panel Lead',
                'phone' => '+20 100 123 4567',
                'source' => LeadSource::Referral->value,
                'priority' => LeadPriority::High->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $lead = Lead::query()->where('name', 'Panel Lead')->sole();
        expect($lead->assigned_to)->not->toBeNull()
            ->and($lead->phone)->toBe('+201001234567')
            ->and($lead->status)->toBe(LeadStatus::New);
    });

    it('audits a status change made in the edit form', function (): void {
        $lead = Lead::factory()->assignedTo($this->agent)->create();

        $this->actingAs($this->agent);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->fillForm(['status' => LeadStatus::Qualified->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $log = LeadStatusLog::query()->where('lead_id', $lead->id)->where('new_status', 'qualified')->sole();
        expect($log->user_id)->toBe($this->agent->id)->and($log->old_status)->toBe(LeadStatus::New);
    });

    it('changes status from the table action', function (): void {
        $lead = Lead::factory()->assignedTo($this->agent)->create();

        $this->actingAs($this->agent);

        Livewire::test(ListLeads::class)
            ->callTableAction('changeStatus', $lead, data: ['status' => LeadStatus::Contacted->value]);

        expect($lead->fresh()->status)->toBe(LeadStatus::Contacted);
    });
});
