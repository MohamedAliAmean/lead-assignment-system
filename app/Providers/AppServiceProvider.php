<?php

declare(strict_types=1);

namespace App\Providers;

use App\Assignment\Contracts\AssignmentStrategy;
use App\Assignment\RoundRobinStrategy;
use App\Authorization\ActorContext;
use App\Authorization\Contracts\CurrentActor;
use App\Authorization\LeadVisibility;
use App\Authorization\Rules\AdminVisibility;
use App\Authorization\Rules\AgentVisibility;
use App\Authorization\Rules\ManagerVisibility;
use App\Enums\UserRole;
use App\Escalation\Contracts\EscalationRecipientResolver;
use App\Escalation\TeamManagerRecipientResolver;
use App\Models\Lead;
use App\Models\WebhookSubscription;
use App\Support\BusinessClock;
use App\Support\SystemClock;
use App\Webhooks\Contracts\WebhookSigner;
use App\Webhooks\HmacSha256Signer;
use App\Webhooks\InboundSourceRegistry;
use App\Webhooks\OutboundUrlGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Tests\Support\Commands\ConcurrencyWorkerCommand;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Interface bindings (DIP): high-level code depends on these contracts.
     */
    public function register(): void
    {
        $this->app->singleton(ClockInterface::class, SystemClock::class);

        $this->app->singleton(BusinessClock::class, fn ($app): BusinessClock => new BusinessClock(
            $app->make(ClockInterface::class),
            (string) config('leads.business_timezone'),
        ));

        // Scoped: reset per request / job, safe under Octane and queue workers.
        $this->app->scoped(ActorContext::class);
        $this->app->scoped(CurrentActor::class, fn ($app) => $app->make(ActorContext::class));

        $this->app->bind(AssignmentStrategy::class, fn ($app): AssignmentStrategy => match (config('leads.assignment_strategy')) {
            'round_robin' => $app->make(RoundRobinStrategy::class),
            default => throw new InvalidArgumentException('Unknown assignment strategy: '.config('leads.assignment_strategy')),
        });

        $this->app->singleton(LeadVisibility::class, fn (): LeadVisibility => new LeadVisibility([
            UserRole::Admin->value => new AdminVisibility,
            UserRole::SalesManager->value => new ManagerVisibility,
            UserRole::SalesAgent->value => new AgentVisibility,
        ]));

        $this->app->bind(EscalationRecipientResolver::class, TeamManagerRecipientResolver::class);

        $this->app->singleton(WebhookSigner::class, HmacSha256Signer::class);
        $this->app->bind(OutboundUrlGuard::class, fn (): OutboundUrlGuard => new OutboundUrlGuard(
            (bool) config('webhooks.outbound.allow_private_urls'),
        ));
        $this->app->bind(InboundSourceRegistry::class, fn (): InboundSourceRegistry => new InboundSourceRegistry(
            (array) config('webhooks.inbound.sources'),
        ));
    }

    public function boot(): void
    {
        // Test-only worker for the multi-process concurrency tests.
        if ($this->app->environment('testing') && $this->app->runningInConsole()
            && class_exists(ConcurrencyWorkerCommand::class)) {
            $this->commands([ConcurrencyWorkerCommand::class]);
        }

        $this->configureApi();

        // N+1 queries throw outside production (constitution §5).
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Log every single query slower than 200 ms, with its bindings' SQL.
        DB::listen(static function (QueryExecuted $event): void {
            if ($event->time >= 200) {
                Log::warning('Slow query detected', ['sql' => $event->sql, 'time_ms' => $event->time]);
            }
        });

        // Log requests/jobs whose total query time exceeds 1 s (too many queries).
        DB::whenQueryingForLongerThan(1000, static function (Connection $connection): void {
            Log::warning('Total query time exceeded 1s', ['connection' => $connection->getName()]);
        });
    }

    private function configureApi(): void
    {
        // API {lead} parameters resolve through the visibility scope, so an
        // inaccessible lead is a 404 before the controller even runs (R11.3).
        Route::bind('lead', static function (string $value, $route): Lead {
            $user = request()->user();
            abort_if($user === null, 401);

            return Lead::query()->visibleTo($user)->whereKey((int) $value)->firstOrFail();
        });
        Route::model('subscription', WebhookSubscription::class);

        RateLimiter::for('api', static fn (Request $request): Limit => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        RateLimiter::for('api-token', static fn (Request $request): Limit => Limit::perMinute(5)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('webhooks', static fn (Request $request): Limit => Limit::perMinute(600)
            ->by((string) $request->header('X-Webhook-Source', $request->ip())));
    }
}
