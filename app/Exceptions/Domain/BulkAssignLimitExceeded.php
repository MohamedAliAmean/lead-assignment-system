<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class BulkAssignLimitExceeded extends DomainException
{
    public function __construct(public readonly int $requested, public readonly int $max)
    {
        parent::__construct(sprintf('At most %d leads can be assigned at once (%d given).', $max, $requested));
    }

    public function errorCode(): string
    {
        return 'bulk_limit_exceeded';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function context(): array
    {
        return ['requested' => $this->requested, 'max' => $this->max];
    }
}
