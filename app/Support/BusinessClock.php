<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Psr\Clock\ClockInterface;

/**
 * Single source of "now" and "today" for the domain (DIP: depends on the PSR-20
 * clock, so tests can freeze or move time).
 *
 * Timestamps are UTC. The business day (daily capacity window, A-3) is the
 * calendar date in the configured business timezone.
 */
final readonly class BusinessClock
{
    public function __construct(
        private ClockInterface $clock,
        private string $businessTimezone,
    ) {}

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->clock->now());
    }

    /**
     * Business date as Y-m-d, e.g. the key of the capacity ledger row.
     */
    public function today(): string
    {
        return $this->now()->setTimezone($this->businessTimezone)->toDateString();
    }
}
