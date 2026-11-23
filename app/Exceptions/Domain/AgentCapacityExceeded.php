<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class AgentCapacityExceeded extends DomainException
{
    public function __construct(
        public readonly int $agentId,
        public readonly int $requested,
        public readonly int $remaining,
    ) {
        parent::__construct(sprintf(
            'The agent can receive %d more lead(s) today, but %d were requested.',
            $remaining,
            $requested,
        ));
    }

    public function errorCode(): string
    {
        return 'capacity_exceeded';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function context(): array
    {
        return ['agent_id' => $this->agentId, 'requested' => $this->requested, 'remaining' => $this->remaining];
    }
}
