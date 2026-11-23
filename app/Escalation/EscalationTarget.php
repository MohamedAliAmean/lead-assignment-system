<?php

declare(strict_types=1);

namespace App\Escalation;

use App\Models\User;
use Illuminate\Support\Collection;

final readonly class EscalationTarget
{
    /**
     * @param  Collection<int, User>  $recipients
     */
    public function __construct(
        public ?User $manager,
        public Collection $recipients,
    ) {}
}
