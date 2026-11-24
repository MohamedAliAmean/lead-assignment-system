<?php

declare(strict_types=1);

namespace App\Escalation\Contracts;

use App\Escalation\EscalationTarget;
use App\Models\Lead;

/**
 * Output port: tell the responsible people that a lead was escalated.
 *
 * The use case (EscalateLead) depends on this interface only; the delivery
 * mechanism (database notification today, mail/Slack tomorrow) is an adapter
 * in the infrastructure layer.
 *
 * Contract: the implementation must write synchronously on the current
 * database connection, so that it commits or rolls back together with the
 * escalation record (design §9.3).
 */
interface EscalationNotifier
{
    public function notify(Lead $lead, EscalationTarget $target): void;
}
