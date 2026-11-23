<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Leads\ChangeLeadStatus;
use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangeLeadStatusRequest;
use App\Http\Resources\V1\LeadResource;
use App\Models\Lead;
use App\Models\User;
use OpenApi\Attributes as OA;

final class LeadStatusController extends Controller
{
    #[OA\Patch(
        path: '/api/v1/leads/{lead}/status',
        operationId: 'changeLeadStatus',
        description: 'Writes an audit entry (old/new status, user, time). Setting the same status is a no-op.',
        summary: 'Change lead status',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['status'],
            properties: [new OA\Property(property: 'status', type: 'string', enum: LeadStatus::class, example: 'contacted')],
        )),
        tags: ['Leads'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LeadId')],
        responses: [
            new OA\Response(response: 200, description: 'Updated lead', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Lead'),
            ])),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ValidationFailed', response: 422),
        ],
    )]
    public function update(ChangeLeadStatusRequest $request, Lead $lead, ChangeLeadStatus $changeStatus): LeadResource
    {
        /** @var User $user */
        $user = $request->user();

        return LeadResource::make($changeStatus->handle($lead, $request->status(), $user)->load('assignedUser:id,name'));
    }
}
