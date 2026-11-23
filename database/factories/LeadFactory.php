<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds lead rows directly (no Round Robin). Use the CreateLead action when a
 * test needs the real assignment flow.
 *
 * @extends Factory<Lead>
 */
final class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '+2010'.fake()->unique()->numerify('########'),
            'source' => fake()->randomElement(LeadSource::cases()),
            'priority' => fake()->randomElement(LeadPriority::cases()),
            'status' => LeadStatus::New,
            'next_follow_up_at' => now()->addHours(fake()->numberBetween(1, 72)),
        ];
    }

    public function assignedTo(User $user): self
    {
        return $this->state(['assigned_to' => $user->getKey(), 'assigned_at' => now()]);
    }

    public function status(LeadStatus $status): self
    {
        return $this->state(['status' => $status]);
    }

    public function highPriority(): self
    {
        return $this->state(['priority' => LeadPriority::High]);
    }

    public function overdue(): self
    {
        return $this->state(['next_follow_up_at' => now()->subHour()]);
    }
}
