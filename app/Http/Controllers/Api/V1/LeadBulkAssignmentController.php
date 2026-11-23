<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Leads\BulkAssignLeads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BulkAssignLeadsRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

final class LeadBulkAssignmentController extends Controller
{
    #[OA\Post(
        path: '/api/v1/leads/bulk-assign',
        operationId: 'bulkAssignLeads',
        description: 'All-or-nothing. Admins may assign to any active agent; managers only to active agents in their team, and only leads they can see. Counts against the agent\'s daily capacity.',
        summary: 'Assign several leads to one agent',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['lead_ids', 'assignee_id'],
            properties: [
                new OA\Property(property: 'lead_ids', type: 'array', items: new OA\Items(type: 'integer'), maxItems: 500, example: [12, 13, 14]),
                new OA\Property(property: 'assignee_id', type: 'integer', example: 7),
            ],
        )),
        tags: ['Leads'],
        responses: [
            new OA\Response(response: 200, description: 'Assigned', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'assigned', type: 'integer', example: 3, description: 'Leads whose assignee changed'),
            ])),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(response: 409, description: 'Target agent lacks daily capacity; nothing assigned', content: new OA\JsonContent(ref: '#/components/schemas/CapacityExceeded')),
            new OA\Response(ref: '#/components/responses/ValidationFailed', response: 422),
        ],
    )]
    public function store(BulkAssignLeadsRequest $request, BulkAssignLeads $bulkAssign): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['assigned' => $bulkAssign->handle($request->toDto(), $user)]);
    }
}
