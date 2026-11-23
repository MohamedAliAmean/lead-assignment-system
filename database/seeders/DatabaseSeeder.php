<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Leads\ChangeLeadStatus;
use App\Actions\Leads\CreateLead;
use App\Data\CreateLeadData;
use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\WebhookEvent;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookSubscription;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Bus;

/**
 * Demo data. Leads are created through the real CreateLead action, so Round
 * Robin, capacity, the audit log and escalation scheduling are all exercised.
 * Every demo account uses the password "password".
 */
final class DatabaseSeeder extends Seeder
{
    public function run(CreateLead $createLead, ChangeLeadStatus $changeStatus): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Admin User', 'email' => 'admin@example.com']);

        $teams = [];
        foreach (['Cairo' => 'cairo', 'Alexandria' => 'alex'] as $teamName => $slug) {
            $manager = User::factory()->manager()->create(['name' => "{$teamName} Manager", 'email' => "manager.{$slug}@example.com"]);
            $team = Team::factory()->managedBy($manager)->create(['name' => "{$teamName} Team"]);

            foreach ([1, 2, 3] as $n) {
                User::factory()->agent()->inTeam($team)->create([
                    'name' => "{$teamName} Agent {$n}",
                    'email' => "agent{$n}.{$slug}@example.com",
                    'daily_capacity' => [1 => 15, 2 => 10, 3 => 8][$n],
                    'is_active' => ! ($slug === 'alex' && $n === 3), // one inactive agent
                ]);
            }

            $teams[] = $team;
        }

        // Keep the demo queue quiet: escalation jobs would otherwise pile up.
        Bus::fake();

        $sources = LeadSource::cases();
        $priorities = LeadPriority::cases();

        for ($i = 1; $i <= 60; $i++) {
            $lead = $createLead->handle(new CreateLeadData(
                name: fake()->name(),
                phone: '+2010'.fake()->unique()->numerify('########'),
                source: $sources[$i % count($sources)],
                priority: $priorities[$i % count($priorities)],
                nextFollowUpAt: now()->toImmutable()->addHours(random_int(-48, 72)),
            ), $admin);

            if ($lead->assigned_to !== null && $i % 3 === 0) {
                $changeStatus->handle($lead, [LeadStatus::Contacted, LeadStatus::Qualified, LeadStatus::Won][intdiv($i, 3) % 3], $admin);
            }
        }

        WebhookSubscription::query()->create([
            'name' => 'Demo receiver (inactive)',
            'url' => 'https://example.com/webhooks/leads',
            'secret' => 'whsec_demo_secret_change_me_0000000000',
            'events' => WebhookEvent::values(),
            'is_active' => false,
        ]);

        $this->command?->info('Demo data ready. Log in at /admin with admin@example.com / password.');
    }
}
