<?php

declare(strict_types=1);

use App\Filament\Widgets\AgentCapacityTable;
use App\Filament\Widgets\LeadsCreatedChart;
use App\Filament\Widgets\LeadStatsOverview;
use App\Filament\Widgets\LeadStatusChart;
use App\Filament\Widgets\NeedsAttentionTable;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use App\Notifications\LeadEscalatedNotification;
use Filament\Notifications\Notification;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->manager = User::factory()->manager()->create();
    $this->team = Team::factory()->managedBy($this->manager)->create();
    $this->agent = User::factory()->agent()->inTeam($this->team)->create(['name' => 'Team Agent']);
    $this->outsider = User::factory()->agent()->create(['name' => 'Outside Agent']);
    $this->ownOverdue = Lead::factory()->assignedTo($this->agent)->overdue()->create(['name' => 'Own Overdue Lead']);
    $this->foreignOverdue = Lead::factory()->assignedTo($this->outsider)->overdue()->create(['name' => 'Foreign Overdue Lead']);
});

it('renders the dashboard for every role', function (string $role): void {
    $this->actingAs($this->{$role})->get('/admin')->assertOk()->assertSee('Lead CRM');
})->with(['admin', 'manager', 'agent']);

it('renders every widget', function (string $widget): void {
    $this->actingAs($this->admin);

    Livewire::test($widget)->assertSuccessful();
})->with([LeadStatsOverview::class, LeadsCreatedChart::class, LeadStatusChart::class, NeedsAttentionTable::class, AgentCapacityTable::class]);

it('shows the unassigned card to admins only', function (): void {
    $this->actingAs($this->admin);
    Livewire::test(LeadStatsOverview::class)->assertSee('Unassigned');

    $this->actingAs($this->agent);
    Livewire::test(LeadStatsOverview::class)->assertDontSee('Unassigned');
});

it('scopes the needs-attention table to visible leads', function (): void {
    $this->actingAs($this->agent);

    Livewire::test(NeedsAttentionTable::class)
        ->assertCanSeeTableRecords([$this->ownOverdue])
        ->assertCanNotSeeTableRecords([$this->foreignOverdue]);
});

it('shows team capacity to managers and hides the widget from agents', function (): void {
    $this->actingAs($this->manager);
    Livewire::test(AgentCapacityTable::class)->assertSee('Team Agent')->assertDontSee('Outside Agent');

    $this->actingAs($this->agent);
    expect(AgentCapacityTable::canView())->toBeFalse();
});

it('stores escalations in a format the notification bell can render', function (): void {
    $this->manager->notifyNow(new LeadEscalatedNotification($this->ownOverdue));

    $stored = $this->manager->notifications()->sole();
    $panel = Notification::fromDatabase($stored);

    expect($stored->data['format'])->toBe('filament')
        ->and($panel->getTitle())->toBe('Lead escalated')
        ->and($stored->data['lead_id'])->toBe($this->ownOverdue->id);
});
