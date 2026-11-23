<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UserSummary',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 7),
        new OA\Property(property: 'name', type: 'string', example: 'Sara Agent'),
    ],
)]
#[OA\Schema(
    schema: 'Lead',
    required: ['id', 'name', 'phone', 'source', 'priority', 'status'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 42),
        new OA\Property(property: 'name', type: 'string', example: 'Ahmed Hassan'),
        new OA\Property(property: 'phone', type: 'string', example: '+201001234567'),
        new OA\Property(property: 'source', type: 'string', enum: LeadSource::class, example: 'website'),
        new OA\Property(property: 'external_ref', type: 'string', nullable: true, example: null),
        new OA\Property(property: 'priority', type: 'integer', enum: [1, 2, 3], example: 3, description: '1 low, 2 medium, 3 high'),
        new OA\Property(property: 'priority_label', type: 'string', example: 'High'),
        new OA\Property(property: 'status', type: 'string', enum: LeadStatus::class, example: 'new'),
        new OA\Property(property: 'assigned_to', type: 'integer', nullable: true, example: 7),
        new OA\Property(property: 'assigned_user', ref: '#/components/schemas/UserSummary', nullable: true),
        new OA\Property(property: 'assigned_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'next_follow_up_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_overdue', type: 'boolean', example: false),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
)]
#[OA\Schema(
    schema: 'LeadStatusLog',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'old_status', type: 'string', enum: LeadStatus::class, nullable: true),
        new OA\Property(property: 'new_status', type: 'string', enum: LeadStatus::class),
        new OA\Property(property: 'changed_by', ref: '#/components/schemas/UserSummary', nullable: true, description: 'NULL = system'),
        new OA\Property(property: 'changed_at', type: 'string', format: 'date-time'),
    ],
)]
#[OA\Schema(
    schema: 'CursorMeta',
    properties: [
        new OA\Property(property: 'path', type: 'string'),
        new OA\Property(property: 'per_page', type: 'integer'),
        new OA\Property(property: 'next_cursor', type: 'string', nullable: true),
        new OA\Property(property: 'prev_cursor', type: 'string', nullable: true),
    ],
)]
final class LeadSchemas {}
