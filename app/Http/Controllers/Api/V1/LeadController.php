<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Leads\CreateLead;
use App\Actions\Leads\UpdateLead;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Http\Queries\LeadIndexQuery;
use App\Http\Requests\Api\V1\ListLeadsRequest;
use App\Http\Requests\Api\V1\StoreLeadRequest;
use App\Http\Requests\Api\V1\UpdateLeadRequest;
use App\Http\Resources\V1\LeadResource;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;

/**
 * Thin controller: validate -> DTO -> Action -> Resource.
 */
final class LeadController extends Controller
{
    #[OA\Get(
        path: '/api/v1/leads',
        operationId: 'listLeads',
        description: 'Leads visible to the caller, newest first, with cursor pagination (fast on deep pages).',
        summary: 'List leads',
        security: [['bearerAuth' => []]],
        tags: ['Leads'],
        parameters: [
            new OA\Parameter(name: 'filter[mine]', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'filter[unassigned]', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'filter[overdue]', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'filter[priority]', in: 'query', description: '"high" or 1/2/3', schema: new OA\Schema(type: 'string', example: 'high')),
            new OA\Parameter(name: 'filter[status]', in: 'query', schema: new OA\Schema(type: 'string', enum: LeadStatus::class)),
            new OA\Parameter(name: 'search', in: 'query', description: 'Name words (FULLTEXT) or phone prefix', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 25, maximum: 100, minimum: 1)),
            new OA\Parameter(name: 'cursor', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Page of leads', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Lead')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/CursorMeta'),
            ])),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ValidationFailed', response: 422),
        ],
    )]
    public function index(ListLeadsRequest $request, LeadIndexQuery $leads): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Lead::class);

        $page = $leads->for($this->user($request), $request)
            ->cursorPaginate((int) $request->validated('per_page', 25))
            ->withQueryString();

        return LeadResource::collection($page);
    }

    #[OA\Post(
        path: '/api/v1/leads',
        operationId: 'createLead',
        description: 'Creates a lead and assigns it automatically (Round Robin). Admins and managers only.',
        summary: 'Create a lead',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['name', 'phone', 'source'],
            properties: [
                new OA\Property(property: 'name', type: 'string', maxLength: 150, example: 'Ahmed Hassan'),
                new OA\Property(property: 'phone', type: 'string', example: '+20 100 123 4567'),
                new OA\Property(property: 'source', type: 'string', enum: LeadSource::class, example: 'website'),
                new OA\Property(property: 'priority', type: 'integer', enum: [1, 2, 3], example: 2),
                new OA\Property(property: 'next_follow_up_at', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'external_ref', type: 'string', nullable: true),
            ],
        )),
        tags: ['Leads'],
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Lead'),
            ])),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationFailed', response: 422),
        ],
    )]
    public function store(StoreLeadRequest $request, CreateLead $createLead): JsonResponse
    {
        Gate::authorize('create', Lead::class);

        $lead = $createLead->handle($request->toDto(), $this->user($request));

        return LeadResource::make($lead->load('assignedUser:id,name'))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/leads/{lead}',
        operationId: 'showLead',
        summary: 'Show a lead',
        security: [['bearerAuth' => []]],
        tags: ['Leads'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LeadId')],
        responses: [
            new OA\Response(response: 200, description: 'The lead', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Lead'),
            ])),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
        ],
    )]
    public function show(Lead $lead): LeadResource
    {
        Gate::authorize('view', $lead);

        return LeadResource::make($lead->load('assignedUser:id,name'));
    }

    #[OA\Patch(
        path: '/api/v1/leads/{lead}',
        operationId: 'updateLead',
        description: 'Partial update. A status change here is audited exactly like PATCH /status.',
        summary: 'Update a lead',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'phone', type: 'string'),
            new OA\Property(property: 'source', type: 'string', enum: LeadSource::class),
            new OA\Property(property: 'priority', type: 'integer', enum: [1, 2, 3]),
            new OA\Property(property: 'next_follow_up_at', type: 'string', format: 'date-time', nullable: true),
            new OA\Property(property: 'status', type: 'string', enum: LeadStatus::class),
        ])),
        tags: ['Leads'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/LeadId')],
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Lead'),
            ])),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ValidationFailed', response: 422),
        ],
    )]
    public function update(UpdateLeadRequest $request, Lead $lead, UpdateLead $updateLead): LeadResource
    {
        $lead = $updateLead->handle($lead, $request->toDto(), $this->user($request));

        return LeadResource::make($lead->load('assignedUser:id,name'));
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
