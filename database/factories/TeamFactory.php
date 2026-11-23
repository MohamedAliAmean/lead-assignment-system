<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
final class TeamFactory extends Factory
{
    protected $model = Team::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Team',
            'manager_id' => null,
        ];
    }

    public function managedBy(User $manager): self
    {
        return $this->state(['manager_id' => $manager->getKey()]);
    }
}
