<?php

declare(strict_types=1);

use App\Enums\LeadSource;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    config(['webhooks.inbound.sources' => ['facebook' => 'fb-test-secret']]);
    $this->agent = User::factory()->agent()->create();
});

/**
 * @param  array<string, mixed>  $payload
 */
function postSignedLead(array $payload, string $secret = 'fb-test-secret', ?int $timestamp = null, string $source = 'facebook'): TestResponse
{
    $timestamp ??= now()->getTimestamp();
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    return test()->call('POST', '/api/v1/webhooks/leads', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_WEBHOOK_SOURCE' => $source,
        'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
    ], content: $body);
}

$payload = ['external_id' => 'fb-1001', 'name' => 'Mona Adel', 'phone' => '+20 111 222 3334', 'priority' => 3];

it('creates and auto-assigns a lead from a correctly signed request', function () use ($payload): void {
    postSignedLead($payload)->assertCreated()->assertJsonPath('created', true);

    $lead = Lead::query()->sole();
    expect($lead->source)->toBe(LeadSource::Facebook)
        ->and($lead->external_ref)->toBe('fb-1001')
        ->and($lead->assigned_to)->toBe($this->agent->id)
        ->and($lead->statusLogs()->count())->toBe(1);
});

it('de-duplicates repeated deliveries of the same external lead', function () use ($payload): void {
    $first = postSignedLead($payload)->assertCreated();
    postSignedLead($payload)->assertOk()->assertJsonPath('created', false)->assertJsonPath('lead_id', $first->json('lead_id'));

    expect(Lead::query()->count())->toBe(1);
});

it('rejects a wrong signature', function () use ($payload): void {
    postSignedLead($payload, secret: 'wrong-secret')->assertUnauthorized()->assertJsonPath('code', 'invalid_webhook_signature');
    expect(Lead::query()->count())->toBe(0);
});

it('rejects replayed (stale) requests even with a valid signature', function () use ($payload): void {
    postSignedLead($payload, timestamp: now()->subMinutes(10)->getTimestamp())->assertUnauthorized();
});

it('rejects unknown sources', function () use ($payload): void {
    postSignedLead($payload, source: 'website')->assertUnauthorized();
});

it('rejects a body that was tampered with after signing', function () use ($payload): void {
    $timestamp = now()->getTimestamp();
    $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.json_encode($payload), 'fb-test-secret');

    $this->call('POST', '/api/v1/webhooks/leads', server: [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_WEBHOOK_SOURCE' => 'facebook', 'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
    ], content: json_encode([...$payload, 'priority' => 1]))->assertUnauthorized();
});

it('validates the payload', function (): void {
    postSignedLead(['name' => 'No phone'])->assertUnprocessable()->assertJsonValidationErrors(['external_id', 'phone']);
});
