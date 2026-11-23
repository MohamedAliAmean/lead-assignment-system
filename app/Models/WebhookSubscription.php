<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WebhookEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $url
 * @property string $secret
 * @property list<string> $events
 * @property bool $is_active
 */
final class WebhookSubscription extends Model
{
    protected $fillable = ['name', 'url', 'secret', 'events', 'is_active'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<WebhookSubscription>  $query
     * @return Builder<WebhookSubscription>
     */
    public function scopeListeningTo(Builder $query, WebhookEvent $event): Builder
    {
        return $query->where('is_active', true)->whereJsonContains('events', $event->value);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'subscription_id');
    }
}
