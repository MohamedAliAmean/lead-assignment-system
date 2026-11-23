<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Root of the OpenAPI document served by Swagger UI at /api/documentation.
 */
#[OA\Info(
    version: '1.0.0',
    description: <<<'MD'
        REST API for the Lead Assignment & Follow-up System.

        **Authentication:** call `POST /api/v1/auth/tokens` with a demo account, then click **Authorize** and paste the token.
        All endpoints apply the same authorization as the admin panel: agents see their own leads, managers their team's, admins all.
        An inaccessible lead returns **404**.

        **Errors** always use the same envelope: `{ "message", "code", "errors"?, "context"? }`.

        **Outbound webhooks** are delivered at least once. Every request carries `X-Webhook-Id` (de-duplicate on it),
        `X-Webhook-Event`, `X-Webhook-Timestamp` and `X-Webhook-Signature: sha256=HMAC_SHA256(secret, "{timestamp}.{body}")`.
        MD,
    title: 'Lead CRM API',
)]
#[OA\Server(url: L5_SWAGGER_CONST_HOST, description: 'This server')]
#[OA\SecurityScheme(securityScheme: 'bearerAuth', type: 'http', description: 'Sanctum personal access token', bearerFormat: 'token', scheme: 'bearer')]
#[OA\SecurityScheme(securityScheme: 'webhookSignature', type: 'apiKey', description: 'HMAC-SHA256 of "{X-Webhook-Timestamp}.{raw body}" with the source secret, as "sha256=<hex>". Also send X-Webhook-Source and X-Webhook-Timestamp.', name: 'X-Webhook-Signature', in: 'header')]
#[OA\Tag(name: 'Auth', description: 'API tokens')]
#[OA\Tag(name: 'Leads', description: 'Lead management (scoped by role)')]
#[OA\Tag(name: 'Webhooks', description: 'Inbound lead capture and outbound subscriptions')]
#[OA\Response(response: 'Unauthenticated', description: 'Missing or invalid token', content: new OA\JsonContent(ref: '#/components/schemas/Error'))]
#[OA\Response(response: 'Forbidden', description: 'Not allowed', content: new OA\JsonContent(ref: '#/components/schemas/Error'))]
#[OA\Response(response: 'NotFound', description: 'Not found or not visible to you', content: new OA\JsonContent(ref: '#/components/schemas/Error'))]
#[OA\Response(response: 'ValidationFailed', description: 'Invalid input', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))]
#[OA\Response(response: 'TooManyRequests', description: 'Rate limit exceeded', content: new OA\JsonContent(ref: '#/components/schemas/Error'))]
#[OA\Parameter(parameter: 'LeadId', name: 'lead', description: 'Lead id', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 42))]
final class OpenApiSpec {}
