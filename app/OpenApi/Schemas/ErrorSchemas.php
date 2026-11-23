<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Error',
    required: ['message', 'code'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Resource not found.'),
        new OA\Property(property: 'code', type: 'string', example: 'not_found'),
        new OA\Property(property: 'context', type: 'object', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'ValidationError',
    required: ['message', 'code', 'errors'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'The phone field is required.'),
        new OA\Property(property: 'code', type: 'string', example: 'validation_failed'),
        new OA\Property(property: 'errors', type: 'object', example: ['phone' => ['The phone field is required.']],
            additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string'))),
    ],
)]
#[OA\Schema(
    schema: 'CapacityExceeded',
    required: ['message', 'code', 'context'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'The agent can receive 1 more lead(s) today, but 3 were requested.'),
        new OA\Property(property: 'code', type: 'string', example: 'capacity_exceeded'),
        new OA\Property(property: 'context', properties: [
            new OA\Property(property: 'agent_id', type: 'integer', example: 7),
            new OA\Property(property: 'requested', type: 'integer', example: 3),
            new OA\Property(property: 'remaining', type: 'integer', example: 1),
        ], type: 'object'),
    ],
)]
final class ErrorSchemas {}
