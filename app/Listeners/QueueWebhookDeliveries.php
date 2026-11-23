<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\DeliveryStatus;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Webhooks\Contracts\ProvidesWebhookPayload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;

/**
 * Fans a domain event out to the subscriptions listening for it (R12.5).
 *
 * Depends only on ProvidesWebhookPayload (DIP), so any new event class is
 * published without touching this listener (OCP). The events dispatch after
 * commit, so rolled-back changes never produce webhooks (R12.6).
 *
 * Idempotent: UNIQUE(subscription_id, event_id) + insertOrIgnore, so a retried
 * listener never creates a second delivery for the same event (R12.8).
 */
final class QueueWebhookDeliveries implements ShouldQueue
{
    public string $queue = 'webhooks';

    public function handle(ProvidesWebhookPayload $event): void
    {
        $subscriptions = WebhookSubscription::query()
            ->listeningTo($event->webhookEvent())
            ->pluck('id');

        foreach ($subscriptions as $subscriptionId) {
            $deliveryId = (string) Str::ulid();

            $inserted = WebhookDelivery::query()->insertOrIgnore([
                'id' => $deliveryId,
                'subscription_id' => $subscriptionId,
                'event' => $event->webhookEvent()->value,
                'event_id' => $event->eventId(),
                'payload' => json_encode($event->webhookPayload(), JSON_THROW_ON_ERROR),
                'status' => DeliveryStatus::Pending->value,
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 1) {
                DeliverWebhook::dispatch($deliveryId);
            }
        }
    }
}
