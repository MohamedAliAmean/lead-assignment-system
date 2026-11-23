<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class LeadsNotAccessible extends DomainException
{
    /**
     * @param  list<int>  $leadIds
     */
    public function __construct(public readonly array $leadIds)
    {
        parent::__construct('Some of the selected leads do not exist or you are not allowed to manage them. Nothing was assigned.');
    }

    public function errorCode(): string
    {
        return 'leads_not_accessible';
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public function context(): array
    {
        return ['lead_ids' => $this->leadIds];
    }
}
