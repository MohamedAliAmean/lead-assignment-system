<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class AssigneeNotEligible extends DomainException
{
    public function __construct(public readonly int $assigneeId)
    {
        parent::__construct('You are not allowed to assign leads to this user, or the user is not an active sales agent.');
    }

    public function errorCode(): string
    {
        return 'assignee_not_eligible';
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public function context(): array
    {
        return ['assignee_id' => $this->assigneeId];
    }
}
