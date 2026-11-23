<?php

declare(strict_types=1);

use App\Exceptions\Domain\DomainException;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\VerifyInboundWebhookSignature;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One JSON error envelope for the whole API: {message, code, errors?, context?}.
 */
$apiError = static fn (string $message, string $code, int $status, array $extra = []): JsonResponse => response()->json(
    array_filter(['message' => $message, 'code' => $code, ...$extra], static fn ($value): bool => $value !== null && $value !== []),
    $status,
);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'webhook.signature' => VerifyInboundWebhookSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($apiError): void {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) use ($apiError): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null; // the admin panel keeps Laravel's default handling
            }

            return match (true) {
                $e instanceof DomainException => $apiError($e->getMessage(), $e->errorCode(), $e->httpStatus(), ['context' => $e->context()]),
                $e instanceof ValidationException => $apiError($e->getMessage(), 'validation_failed', 422, ['errors' => $e->errors()]),
                $e instanceof AuthenticationException => $apiError('Unauthenticated.', 'unauthenticated', 401),
                $e instanceof AuthorizationException,
                $e instanceof AccessDeniedHttpException => $apiError('This action is unauthorized.', 'forbidden', 403),
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => $apiError('Resource not found.', 'not_found', 404),
                $e instanceof HttpExceptionInterface => $apiError($e->getMessage() ?: 'HTTP error.', 'http_error', $e->getStatusCode()),
                default => null,
            };
        });
    })->create();
