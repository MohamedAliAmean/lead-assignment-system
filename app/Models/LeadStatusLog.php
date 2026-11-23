<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeadStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only status audit entry (R4).
 *
 * @property int $id
 * @property int $lead_id
 * @property int|null $user_id
 * @property LeadStatus|null $old_status
 * @property LeadStatus $new_status
 * @property CarbonImmutable $changed_at
 */
final class LeadStatusLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['lead_id', 'user_id', 'old_status', 'new_status', 'changed_at'];

    protected function casts(): array
    {
        return [
            'old_status' => LeadStatus::class,
            'new_status' => LeadStatus::class,
            'changed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
