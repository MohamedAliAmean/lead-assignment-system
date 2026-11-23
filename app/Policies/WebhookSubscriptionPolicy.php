<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WebhookSubscription;

final class WebhookSubscriptionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->is_active && $actor->isAdmin();
    }

    public function view(User $actor, WebhookSubscription $subscription): bool
    {
        return $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function update(User $actor, WebhookSubscription $subscription): bool
    {
        return $this->viewAny($actor);
    }

    public function delete(User $actor, WebhookSubscription $subscription): bool
    {
        return $this->viewAny($actor);
    }

    public function deleteAny(User $actor): bool
    {
        return $this->viewAny($actor);
    }
}
