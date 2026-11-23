<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Authorization\Contracts\CurrentActor;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * Resolves the current actor: an explicit actor set by an Action takes
 * precedence over the authenticated user. Actions always pass their actor
 * explicitly, so the audit is correct from HTTP, the API, CLI and jobs alike.
 */
final class ActorContext implements CurrentActor
{
    private bool $overridden = false;

    private ?int $actorId = null;

    public function __construct(private readonly AuthFactory $auth) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(?User $actor, Closure $callback): mixed
    {
        [$previousOverridden, $previousId] = [$this->overridden, $this->actorId];

        $this->overridden = true;
        $this->actorId = $actor?->getKey();

        try {
            return $callback();
        } finally {
            [$this->overridden, $this->actorId] = [$previousOverridden, $previousId];
        }
    }

    public function id(): ?int
    {
        if ($this->overridden) {
            return $this->actorId;
        }

        $id = $this->auth->guard()->id();

        return $id === null ? null : (int) $id;
    }
}
