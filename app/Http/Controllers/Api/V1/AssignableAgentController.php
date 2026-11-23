<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Authorization\AssigneeEligibility;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\BusinessClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

final class AssignableAgentController extends Controller
{
    #[OA\Get(
        path: '/api/v1/assignable-agents',
        operationId: 'assignableAgents',
        description: 'Agents the caller may assign leads to (admins: all active agents; managers: active agents of their team; agents: none), with today\'s remaining capacity. Same rule the bulk-assign endpoint enforces.',
        summary: 'Agents you can assign leads to',
        security: [['bearerAuth' => []]],
        tags: ['Leads'],
        responses: [
            new OA\Response(response: 200, description: 'Agents', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AgentCapacity')),
            ])),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
        ],
    )]
    public function __invoke(Request $request, AssigneeEligibility $eligibility, BusinessClock $clock): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $eligibility->assignableAgentsWithRemaining($user, $clock->today())->values()]);
    }
}
