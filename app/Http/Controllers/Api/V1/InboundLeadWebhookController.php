<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Leads\CreateLead;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InboundLeadWebhookRequest;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

final class InboundLeadWebhookController extends Controller
{
    #[OA\Post(
        path: '/api/v1/webhooks/leads',
        operationId: 'inboundLeadWebhook',
        description: 'For external lead sources (website, Facebook, Google Ads). Uses the same CreateLead flow as the panel: Round Robin, audit, escalation. Idempotent on (source, external_id): a repeated delivery returns the existing lead with 200.',
        summary: 'Receive a lead from an external source',
        security: [['webhookSignature' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['external_id', 'name', 'phone'],
            properties: [
                new OA\Property(property: 'external_id', type: 'string', example: 'fb-lead-981234'),
                new OA\Property(property: 'name', type: 'string', example: 'Mona Adel'),
                new OA\Property(property: 'phone', type: 'string', example: '+201112223334'),
                new OA\Property(property: 'priority', type: 'integer', enum: [1, 2, 3], example: 2),
            ],
        )),
        tags: ['Webhooks'],
        parameters: [
            new OA\Parameter(name: 'X-Webhook-Source', in: 'header', required: true, schema: new OA\Schema(type: 'string', enum: ['website', 'facebook', 'google_ads'])),
            new OA\Parameter(name: 'X-Webhook-Timestamp', in: 'header', required: true, description: 'Unix seconds; must be within 5 minutes', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Lead created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'lead_id', type: 'integer'),
                new OA\Property(property: 'created', type: 'boolean', example: true),
            ])),
            new OA\Response(response: 200, description: 'Duplicate delivery; existing lead returned', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'lead_id', type: 'integer'),
                new OA\Property(property: 'created', type: 'boolean', example: false),
            ])),
            new OA\Response(response: 401, description: 'Unknown source, bad signature or stale timestamp', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(ref: '#/components/responses/ValidationFailed', response: 422),
        ],
    )]
    public function store(InboundLeadWebhookRequest $request, CreateLead $createLead): JsonResponse
    {
        $lead = $createLead->handle($request->toDto());

        return response()->json(
            ['lead_id' => $lead->getKey(), 'created' => $lead->wasRecentlyCreated],
            $lead->wasRecentlyCreated ? 201 : 200,
        );
    }
}
