<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use RuntimeException;

/**
 * Base class for business-rule violations. Each delivery layer (API, Filament)
 * maps these to its own output in one place (design §7.6).
 */
abstract class DomainException extends RuntimeException
{
    /**
     * Stable machine-readable code for API clients.
     */
    abstract public function errorCode(): string;

    /**
     * HTTP status the API should answer with.
     */
    abstract public function httpStatus(): int;

    /**
     * Extra structured context for API clients.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [];
    }
}
