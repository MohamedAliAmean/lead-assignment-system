<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $subscription_id
 * @property string $event
 * @property string $event_id
 * @property array<string, mixed> $payload
 * @property DeliveryStatus $status
 * @property int $attempts
 * @property int|null $response_status
 * @property string|null $last_error
 * @property CarbonImmutable|null $delivered_at
 */
final class WebhookDelivery extends Model
{
    use HasUlids;

    protected $fillable = ['subscription_id', 'event', 'event_id', 'payload', 'status', 'attempts', 'response_status', 'last_error', 'delivered_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'response_status' => 'integer',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<WebhookSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'subscription_id');
    }
}
