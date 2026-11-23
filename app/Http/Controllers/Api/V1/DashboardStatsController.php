<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\LeadResource;
use App\Models\User;
use App\Queries\LeadDashboardStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AgentCapacity',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 3),
        new OA\Property(property: 'name', type: 'string', example: 'Cairo Agent 1'),
        new OA\Property(property: 'capacity', type: 'integer', example: 15),
        new OA\Property(property: 'used', type: 'integer', example: 4),
        new OA\Property(property: 'remaining', type: 'integer', example: 11),
    ],
)]
final class DashboardStatsController extends Controller
{
    #[OA\Get(
        path: '/api/v1/dashboard/stats',
        operationId: 'dashboardStats',
        description: 'The same figures as the admin dashboard, scoped to what the caller may see. Cached for 60 seconds per scope.',
        summary: 'Dashboard statistics',
        security: [['bearerAuth' => []]],
        tags: ['Leads'],
        responses: [
            new OA\Response(response: 200, description: 'Statistics', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'summary', properties: [
                        new OA\Property(property: 'open', type: 'integer'),
                        new OA\Property(property: 'overdue', type: 'integer'),
                        new OA\Property(property: 'new_today', type: 'integer'),
                        new OA\Property(property: 'unassigned', type: 'integer', nullable: true, description: 'Admins only'),
                        new OA\Property(property: 'escalated_today', type: 'integer'),
                    ], type: 'object'),
                    new OA\Property(property: 'created_per_day', type: 'object', example: ['2026-11-22' => 31, '2026-11-23' => 18], additionalProperties: new OA\AdditionalProperties(type: 'integer')),
                    new OA\Property(property: 'status_breakdown', type: 'object', example: ['new' => 12, 'contacted' => 5], additionalProperties: new OA\AdditionalProperties(type: 'integer')),
                    new OA\Property(property: 'capacity_today', type: 'array', items: new OA\Items(ref: '#/components/schemas/AgentCapacity')),
                    new OA\Property(property: 'needs_attention', type: 'array', items: new OA\Items(ref: '#/components/schemas/Lead')),
                ], type: 'object'),
            ])),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
        ],
    )]
    public function __invoke(Request $request, LeadDashboardStats $stats): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => [
            'summary' => $stats->summary($user),
            'created_per_day' => $stats->createdPerDay($user),
            'status_breakdown' => $stats->statusBreakdown($user),
            'capacity_today' => $stats->capacityToday($user)->values(),
            'needs_attention' => LeadResource::collection($stats->needsAttention($user)),
        ]]);
    }
}
