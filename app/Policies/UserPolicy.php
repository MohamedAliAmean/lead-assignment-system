<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Only admins manage users. Users are never deleted (leads keep their history);
 * they are deactivated instead.
 */
final class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->is_active && $actor->isAdmin();
    }

    public function view(User $actor, User $user): bool
    {
        return $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function update(User $actor, User $user): bool
    {
        return $this->viewAny($actor);
    }

    public function delete(User $actor, User $user): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
