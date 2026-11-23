<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\LeadStatusLogResource;
use App\Models\Lead;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;

final class LeadStatusHistoryController extends Controller
{
    #[OA\Get(
        path: '/api/v1/leads/{lead}/status-history',
        operationId: 'leadStatusHistory',
        summary: 'Status audit log of a lead (newest first)',
        security: [['bearerAuth' => []]],
        tags: ['Leads'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/LeadId'),
            new OA\Parameter(name: 'cursor', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Audit entries', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/LeadStatusLog')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/CursorMeta'),
            ])),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
        ],
    )]
    public function index(Lead $lead): AnonymousResourceCollection
    {
        Gate::authorize('view', $lead);

        return LeadStatusLogResource::collection(
            $lead->statusLogs()->with('user:id,name')->cursorPaginate(25),
        );
    }
}
