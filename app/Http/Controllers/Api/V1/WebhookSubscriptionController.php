<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Webhooks\CreateWebhookSubscription;
use App\Enums\WebhookEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreWebhookSubscriptionRequest;
use App\Http\Resources\V1\WebhookSubscriptionResource;
use App\Models\User;
use App\Models\WebhookSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'WebhookSubscription',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name', type: 'string', example: 'ERP sync'),
        new OA\Property(property: 'url', type: 'string', example: 'https://erp.example.com/hooks/leads'),
        new OA\Property(property: 'events', type: 'array', items: new OA\Items(type: 'string', enum: WebhookEvent::class)),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'secret', type: 'string', description: 'Only returned once, on creation'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
)]
final class WebhookSubscriptionController extends Controller
{
    #[OA\Get(
        path: '/api/v1/webhook-subscriptions',
        operationId: 'listWebhookSubscriptions',
        summary: 'List outbound webhook subscriptions (admin)',
        security: [['bearerAuth' => []]],
        tags: ['Webhooks'],
        responses: [
            new OA\Response(response: 200, description: 'Subscriptions', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WebhookSubscription')),
            ])),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', WebhookSubscription::class);

        return WebhookSubscriptionResource::collection(WebhookSubscription::query()->orderBy('id')->paginate(50));
    }

    #[OA\Post(
        path: '/api/v1/webhook-subscriptions',
        operationId: 'createWebhookSubscription',
        description: 'The URL must be https and resolve to a public address. If no secret is given, one is generated and returned once.',
        summary: 'Subscribe an endpoint to lead events (admin)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['name', 'url', 'events'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'ERP sync'),
                new OA\Property(property: 'url', type: 'string', example: 'https://erp.example.com/hooks/leads'),
                new OA\Property(property: 'events', type: 'array', items: new OA\Items(type: 'string', enum: WebhookEvent::class), example: ['lead.created', 'lead.escalated']),
                new OA\Property(property: 'secret', type: 'string', minLength: 16, nullable: true),
            ],
        )),
        tags: ['Webhooks'],
        responses: [
            new OA\Response(response: 201, description: 'Created (includes the secret)', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/WebhookSubscription'),
            ])),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationFailed', response: 422),
        ],
    )]
    public function store(StoreWebhookSubscriptionRequest $request, CreateWebhookSubscription $create): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $subscription = $create->handle(
            $user,
            (string) $request->validated('name'),
            (string) $request->validated('url'),
            $request->events(),
            $request->validated('secret'),
        );

        return WebhookSubscriptionResource::make($subscription)->withSecret()->response()->setStatusCode(201);
    }

    #[OA\Delete(
        path: '/api/v1/webhook-subscriptions/{subscription}',
        operationId: 'deleteWebhookSubscription',
        summary: 'Delete a subscription (admin)',
        security: [['bearerAuth' => []]],
        tags: ['Webhooks'],
        parameters: [new OA\Parameter(name: 'subscription', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
        ],
    )]
    public function destroy(WebhookSubscription $subscription): Response
    {
        Gate::authorize('delete', $subscription);
        $subscription->delete();

        return response()->noContent();
    }
}
