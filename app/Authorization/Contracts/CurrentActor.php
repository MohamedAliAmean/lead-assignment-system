<?php

declare(strict_types=1);

namespace App\Authorization\Contracts;

/**
 * Who is performing the current operation. NULL means the system (a queued
 * job, the scheduler). Used by the status audit (R4.4).
 */
interface CurrentActor
{
    public function id(): ?int;
}
