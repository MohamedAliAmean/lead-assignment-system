<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Real clock. Uses Carbon so Laravel's time travel helpers work in tests.
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return CarbonImmutable::now();
    }
}
