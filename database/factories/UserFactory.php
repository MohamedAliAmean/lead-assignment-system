<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::SalesAgent,
            'is_active' => true,
            'daily_capacity' => (int) config('leads.default_daily_capacity'),
            'assignment_weight' => 1,
        ];
    }

    public function admin(): self
    {
        return $this->state(['role' => UserRole::Admin]);
    }

    public function manager(): self
    {
        return $this->state(['role' => UserRole::SalesManager]);
    }

    public function agent(): self
    {
        return $this->state(['role' => UserRole::SalesAgent]);
    }

    public function inactive(): self
    {
        return $this->state(['is_active' => false]);
    }

    public function capacity(int $capacity): self
    {
        return $this->state(['daily_capacity' => $capacity]);
    }

    public function inTeam(Team $team): self
    {
        return $this->state(['team_id' => $team->getKey()]);
    }
}
