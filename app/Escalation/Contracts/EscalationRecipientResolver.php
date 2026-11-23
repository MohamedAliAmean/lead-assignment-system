<?php

declare(strict_types=1);

namespace App\Escalation\Contracts;

use App\Escalation\EscalationTarget;
use App\Models\Lead;

/**
 * Decides who is responsible for a stale lead. Swappable (for example an
 * on-call manager rotation) without touching EscalateLead (OCP / DIP).
 */
interface EscalationRecipientResolver
{
    public function resolve(Lead $lead): EscalationTarget;
}
