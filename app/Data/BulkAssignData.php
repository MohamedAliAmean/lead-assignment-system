<?php

declare(strict_types=1);

namespace App\Data;

final readonly class BulkAssignData
{
    /** @var list<int> */
    public array $leadIds;

    /**
     * @param  iterable<int|string>  $leadIds
     */
    public function __construct(iterable $leadIds, public int $assigneeId)
    {
        $ids = [];

        foreach ($leadIds as $id) {
            $ids[(int) $id] = (int) $id;
        }

        ksort($ids);
        $this->leadIds = array_values($ids);
    }
}
