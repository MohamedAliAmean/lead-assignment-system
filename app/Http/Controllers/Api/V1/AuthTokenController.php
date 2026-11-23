<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IssueTokenRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

final class AuthTokenController extends Controller
{
    #[OA\Post(
        path: '/api/v1/auth/tokens',
        operationId: 'issueToken',
        summary: 'Issue an API token',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@example.com'),
                new OA\Property(property: 'password', type: 'string', example: 'password'),
                new OA\Property(property: 'device_name', type: 'string', example: 'swagger'),
            ],
        )),
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 201, description: 'Token issued', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'token', type: 'string', example: '1|n2Ji...'),
                new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                new OA\Property(property: 'user', properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'role', type: 'string', example: 'admin'),
                ], type: 'object'),
            ])),
            new OA\Response(ref: '#/components/responses/ValidationFailed', response: 422),
            new OA\Response(ref: '#/components/responses/TooManyRequests', response: 429),
        ],
    )]
    public function store(IssueTokenRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();

        if ($user === null || ! $user->is_active || ! Hash::check((string) $request->validated('password'), $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match an active account.']);
        }

        $token = $user->createToken((string) $request->validated('device_name', 'api'));

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role->value],
        ], 201);
    }

    #[OA\Delete(
        path: '/api/v1/auth/tokens/current',
        operationId: 'revokeCurrentToken',
        summary: 'Revoke the token used for this request',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 204, description: 'Revoked'),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
        ],
    )]
    public function destroy(Request $request): Response
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->noContent();
    }
}
