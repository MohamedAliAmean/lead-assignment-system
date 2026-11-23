<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $lead_id
 * @property int|null $manager_id
 * @property CarbonImmutable $escalated_at
 */
final class LeadEscalation extends Model
{
    public $timestamps = false;

    protected $fillable = ['lead_id', 'manager_id', 'escalated_at'];

    protected function casts(): array
    {
        return ['escalated_at' => 'immutable_datetime'];
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
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }
}
