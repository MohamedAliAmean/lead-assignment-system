<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property int|null $team_id
 * @property bool $is_active
 * @property int $daily_capacity
 * @property int $assignment_weight
 */
final class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'team_id',
        'is_active',
        'daily_capacity',
        'assignment_weight',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'daily_capacity' => 'integer',
            'assignment_weight' => 'integer',
        ];
    }

    /**
     * Inactive users cannot sign in to the panel (R6.7).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isManager(): bool
    {
        return $this->role === UserRole::SalesManager;
    }

    public function isAgent(): bool
    {
        return $this->role === UserRole::SalesAgent;
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The team this user manages (managers only).
     *
     * @return HasOne<Team, $this>
     */
    public function managedTeam(): HasOne
    {
        return $this->hasOne(Team::class, 'manager_id');
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function assignedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_to');
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActiveAgents(Builder $query): Builder
    {
        return $query->where('role', UserRole::SalesAgent->value)->where('is_active', true);
    }
}
