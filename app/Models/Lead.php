<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Builders\LeadBuilder;
use App\Observers\LeadObserver;
use Carbon\CarbonImmutable;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $name
 * @property string $phone
 * @property LeadSource $source
 * @property string|null $external_ref
 * @property LeadPriority $priority
 * @property LeadStatus $status
 * @property int|null $assigned_to
 * @property CarbonImmutable|null $assigned_at
 * @property CarbonImmutable|null $next_follow_up_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 *
 * @method static LeadBuilder query()
 */
#[UseEloquentBuilder(LeadBuilder::class)]
#[ObservedBy(LeadObserver::class)]
final class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    /**
     * Assignment columns are deliberately NOT fillable: they are only written
     * by the assignment services, never by mass assignment from user input.
     */
    protected $fillable = [
        'name',
        'phone',
        'source',
        'external_ref',
        'priority',
        'status',
        'next_follow_up_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => LeadSource::class,
            'priority' => LeadPriority::class,
            'status' => LeadStatus::class,
            'assigned_to' => 'integer',
            'assigned_at' => 'immutable_datetime',
            'next_follow_up_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isOverdue(CarbonImmutable $now): bool
    {
        return $this->status->isOpen()
            && $this->next_follow_up_at !== null
            && $this->next_follow_up_at->lessThan($now);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return HasMany<LeadStatusLog, $this>
     */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(LeadStatusLog::class)->orderByDesc('changed_at')->orderByDesc('id');
    }

    /**
     * @return HasOne<LeadEscalation, $this>
     */
    public function escalation(): HasOne
    {
        return $this->hasOne(LeadEscalation::class);
    }
}
