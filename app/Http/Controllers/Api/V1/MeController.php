<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Assignment\CapacityLedger;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\User;
use App\Support\BusinessClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

final class MeController extends Controller
{
    #[OA\Get(
        path: '/api/v1/me',
        operationId: 'me',
        description: 'Profile of the token owner, plus today\'s capacity usage for agents.',
        summary: 'Current user',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'Current user', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'id', type: 'integer', example: 3),
                    new OA\Property(property: 'name', type: 'string', example: 'Cairo Agent 1'),
                    new OA\Property(property: 'email', type: 'string', example: 'agent1.cairo@example.com'),
                    new OA\Property(property: 'role', type: 'string', enum: UserRole::class),
                    new OA\Property(property: 'role_label', type: 'string', example: 'Sales Agent'),
                    new OA\Property(property: 'team', ref: '#/components/schemas/UserSummary', nullable: true),
                    new OA\Property(property: 'can_assign', type: 'boolean'),
                    new OA\Property(property: 'capacity_today', nullable: true, properties: [
                        new OA\Property(property: 'capacity', type: 'integer', example: 15),
                        new OA\Property(property: 'used', type: 'integer', example: 4),
                        new OA\Property(property: 'remaining', type: 'integer', example: 11),
                    ], type: 'object'),
                ], type: 'object'),
            ])),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
        ],
    )]
    public function __invoke(Request $request, CapacityLedger $ledger, BusinessClock $clock): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->isManager() ? $user->managedTeam()->first(['id', 'name']) : $user->team()->first(['id', 'name']);
        $used = $user->isAgent() ? $ledger->used($user->id, $clock->today()) : null;

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'team' => $team === null ? null : ['id' => $team->id, 'name' => $team->name],
            'can_assign' => $user->can('assign', Lead::class),
            'capacity_today' => $used === null ? null : [
                'capacity' => $user->daily_capacity,
                'used' => $used,
                'remaining' => max(0, $user->daily_capacity - $used),
            ],
        ]]);
    }
}
