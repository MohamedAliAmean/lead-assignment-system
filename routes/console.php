<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Escalation safety net (design §9.3). Idempotent, so overlaps are harmless,
// but withoutOverlapping keeps the scheduler tidy.
Schedule::command('leads:escalate-stale')->everyMinute()->withoutOverlapping();
