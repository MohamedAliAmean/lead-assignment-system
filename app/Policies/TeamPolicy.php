<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

final class TeamPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->is_active && $actor->isAdmin();
    }

    public function view(User $actor, Team $team): bool
    {
        return $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function update(User $actor, Team $team): bool
    {
        return $this->viewAny($actor);
    }

    public function delete(User $actor, Team $team): bool
    {
        return $this->viewAny($actor);
    }

    public function deleteAny(User $actor): bool
    {
        return $this->viewAny($actor);
    }
}
