<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Enums\WebhookEvent;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Webhooks\OutboundUrlGuard;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Str;

/**
 * Registers an outbound webhook endpoint (R12.4). The URL is SSRF-checked
 * (R12.9) and a strong secret is generated when none is given.
 */
final readonly class CreateWebhookSubscription
{
    public function __construct(
        private Gate $gate,
        private OutboundUrlGuard $guard,
    ) {}

    /**
     * @param  list<WebhookEvent>  $events
     */
    public function handle(User $actor, string $name, string $url, array $events, ?string $secret = null): WebhookSubscription
    {
        $this->gate->forUser($actor)->authorize('create', WebhookSubscription::class);
        $this->guard->assertAllowed($url);

        return WebhookSubscription::query()->create([
            'name' => $name,
            'url' => $url,
            'secret' => $secret ?: 'whsec_'.Str::random(40),
            'events' => array_values(array_unique(array_map(static fn (WebhookEvent $event): string => $event->value, $events))),
            'is_active' => true,
        ]);
    }
}
