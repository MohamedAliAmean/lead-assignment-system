<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Business settings injected into the domain as a value object, so use cases
 * never reach into global configuration (no config() service location in
 * inner layers). Built once from config/leads.php in AppServiceProvider.
 */
final readonly class LeadSettings
{
    public function __construct(
        public int $escalationDelayMinutes,
        public int $bulkAssignMax,
    ) {}
}
