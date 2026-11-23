<?php

declare(strict_types=1);

use App\Actions\Leads\ChangeLeadStatus;
use App\Actions\Webhooks\CreateWebhookSubscription;
use App\Enums\DeliveryStatus;
use App\Enums\LeadStatus;
use App\Enums\WebhookEvent;
use App\Events\LeadCreated;
use App\Exceptions\Domain\InvalidWebhookUrl;
use App\Jobs\DeliverWebhook;
use App\Listeners\QueueWebhookDeliveries;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Webhooks\OutboundUrlGuard;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->agent = User::factory()->agent()->create();
    $this->subscription = WebhookSubscription::query()->create([
        'name' => 'ERP', 'url' => 'https://erp.example.com/hooks', 'secret' => 'whsec_test_secret_123',
        'events' => [WebhookEvent::LeadCreated->value, WebhookEvent::LeadStatusChanged->value], 'is_active' => true,
    ]);
    // Resolve the test host to a public IP without real DNS.
    app()->instance(OutboundUrlGuard::class, new OutboundUrlGuard(false, fn (): array => ['93.184.216.34']));
});

it('sends signed webhooks for subscribed events only', function (): void {
    Http::fake(['erp.example.com/*' => Http::response(['ok' => true])]);

    $lead = createLead(); // lead.created (subscribed) + lead.assigned (not subscribed)

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($lead): bool {
        $expected = 'sha256='.hash_hmac('sha256', $request->header('X-Webhook-Timestamp')[0].'.'.$request->body(), 'whsec_test_secret_123');

        return $request->header('X-Webhook-Event')[0] === 'lead.created'
            && $request->header('X-Webhook-Signature')[0] === $expected
            && $request['data']['lead_id'] === $lead->id
            && WebhookDelivery::query()->whereKey($request->header('X-Webhook-Id')[0])->exists();
    });

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->status)->toBe(DeliveryStatus::Delivered)->and($delivery->attempts)->toBe(1);
});

it('emits nothing when the transaction rolls back', function (): void {
    Http::fake();

    try {
        DB::transaction(function (): void {
            createLead();
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    Http::assertNothingSent();
    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('never creates two deliveries for the same event, even if the listener runs twice', function (): void {
    Queue::fake([DeliverWebhook::class]);
    $event = new LeadCreated(1, 'website', 2);
    $listener = new QueueWebhookDeliveries;

    $listener->handle($event);
    $listener->handle($event);

    expect(WebhookDelivery::query()->count())->toBe(1);
    Queue::assertPushed(DeliverWebhook::class, 1);
});

it('records failures and throws so the queue retries with backoff', function (): void {
    Queue::fake([DeliverWebhook::class]);
    createLead();
    $delivery = WebhookDelivery::query()->sole();
    Http::fake(['*' => Http::response('down', 503)]);

    expect(fn () => app()->call([new DeliverWebhook($delivery->id), 'handle']))->toThrow(RuntimeException::class);

    $delivery->refresh();
    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->response_status)->toBe(503)
        ->and((new DeliverWebhook($delivery->id))->backoff)->toBe([10, 30, 120, 600, 1800]);

    (new DeliverWebhook($delivery->id))->failed(new RuntimeException('gave up'));
    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Failed);
});

it('does not resend a delivery that already succeeded', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    createLead();
    $delivery = WebhookDelivery::query()->sole();

    app()->call([new DeliverWebhook($delivery->id), 'handle']);

    Http::assertSentCount(1);
});

it('publishes status changes', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $lead = createLead();

    app(ChangeLeadStatus::class)->handle($lead, LeadStatus::Contacted, $this->agent);

    Http::assertSent(fn (Request $request): bool => $request->header('X-Webhook-Event')[0] === 'lead.status_changed'
        && $request['data']['old_status'] === 'new' && $request['data']['new_status'] === 'contacted');
});

it('blocks SSRF targets', function (string $url, array $resolvesTo): void {
    $guard = new OutboundUrlGuard(false, fn (): array => $resolvesTo);

    expect(fn () => $guard->assertAllowed($url))->toThrow(InvalidWebhookUrl::class);
})->with([
    'plain http' => ['http://erp.example.com/hook', ['93.184.216.34']],
    'loopback' => ['https://localhost/hook', ['127.0.0.1']],
    'private network' => ['https://intranet.example.com', ['10.0.0.5']],
    'cloud metadata' => ['https://169.254.169.254/latest/meta-data', []],
    'not a url' => ['ftp://example.com', ['93.184.216.34']],
]);

it('lets only admins manage subscriptions over the API', function (): void {
    $admin = User::factory()->admin()->create();

    Sanctum::actingAs($this->agent);
    $this->getJson('/api/v1/webhook-subscriptions')->assertForbidden();

    Sanctum::actingAs($admin);
    $this->postJson('/api/v1/webhook-subscriptions', [
        'name' => 'BI', 'url' => 'https://bi.example.com/in', 'events' => ['lead.escalated'],
    ])->assertCreated()->assertJsonStructure(['data' => ['id', 'secret']]);

    $this->postJson('/api/v1/webhook-subscriptions', [
        'name' => 'Bad', 'url' => 'https://10.0.0.1/in', 'events' => ['lead.created'],
    ])->assertUnprocessable()->assertJsonPath('code', 'invalid_webhook_url');

    $this->getJson('/api/v1/webhook-subscriptions')->assertOk()->assertJsonMissingPath('data.0.secret');
});

it('creates subscriptions with a generated secret', function (): void {
    $subscription = app(CreateWebhookSubscription::class)->handle(
        User::factory()->admin()->create(), 'X', 'https://x.example.com/h', [WebhookEvent::LeadCreated],
    );

    expect($subscription->secret)->toStartWith('whsec_')->toHaveLength(46)
        ->and(DB::table('webhook_subscriptions')->value('secret'))->not->toContain('whsec_'); // encrypted at rest
});
