<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DeliveryStatus;
use App\Exceptions\Domain\InvalidWebhookUrl;
use App\Models\WebhookDelivery;
use App\Webhooks\Contracts\WebhookSigner;
use App\Webhooks\OutboundUrlGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Factory as Http;
use RuntimeException;
use Throwable;

/**
 * Sends one signed webhook delivery (R12.7) with retries and backoff (R12.8).
 *
 * At-least-once: if the receiver accepted the request but we crashed before
 * recording it, the retry sends it again; receivers de-duplicate on
 * X-Webhook-Id. A delivered row is never sent again.
 */
final class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /** @var list<int> */
    public array $backoff;

    public function __construct(public readonly string $deliveryId)
    {
        $this->onQueue('webhooks');
        $this->tries = (int) config('webhooks.outbound.tries');
        $this->backoff = config('webhooks.outbound.backoff');
    }

    public function handle(Http $http, WebhookSigner $signer, OutboundUrlGuard $guard): void
    {
        /** @var WebhookDelivery|null $delivery */
        $delivery = WebhookDelivery::query()->with('subscription')->find($this->deliveryId);

        if ($delivery === null || $delivery->status === DeliveryStatus::Delivered || ! $delivery->subscription->is_active) {
            return;
        }

        try {
            $guard->assertAllowed($delivery->subscription->url); // re-checked at send time (DNS rebinding)
        } catch (InvalidWebhookUrl $e) {
            $this->markFailed($delivery, $e->getMessage());

            return; // retrying cannot fix a forbidden URL
        }

        $body = json_encode($delivery->payload, JSON_THROW_ON_ERROR);
        $timestamp = now()->getTimestamp();

        $delivery->increment('attempts');

        try {
            $response = $http->timeout((int) config('webhooks.outbound.timeout'))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'LeadCRM-Webhooks/1.0',
                    'X-Webhook-Id' => $delivery->id,
                    'X-Webhook-Event' => $delivery->event,
                    'X-Webhook-Timestamp' => (string) $timestamp,
                    'X-Webhook-Signature' => $signer->sign($delivery->subscription->secret, $timestamp, $body),
                ])
                ->withBody($body, 'application/json')
                ->withoutRedirecting()
                ->post($delivery->subscription->url);
        } catch (Throwable $e) {
            $delivery->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 1000)])->save();

            throw $e; // retry with backoff
        }

        if ($response->successful()) {
            // Conditional update: only the first success flips the state.
            WebhookDelivery::query()
                ->whereKey($delivery->id)
                ->where('status', '!=', DeliveryStatus::Delivered->value)
                ->update([
                    'status' => DeliveryStatus::Delivered->value,
                    'response_status' => $response->status(),
                    'last_error' => null,
                    'delivered_at' => now(),
                    'updated_at' => now(),
                ]);

            return;
        }

        $delivery->forceFill([
            'response_status' => $response->status(),
            'last_error' => mb_substr($response->body(), 0, 1000),
        ])->save();

        throw new RuntimeException("Webhook endpoint answered HTTP {$response->status()}.");
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if ($delivery !== null && $delivery->status !== DeliveryStatus::Delivered) {
            $this->markFailed($delivery, $delivery->last_error ?? $exception?->getMessage() ?? 'Unknown error');
        }
    }

    private function markFailed(WebhookDelivery $delivery, string $error): void
    {
        $delivery->forceFill(['status' => DeliveryStatus::Failed, 'last_error' => mb_substr($error, 0, 1000)])->save();
    }
}
