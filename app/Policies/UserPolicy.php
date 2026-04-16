<?php

namespace App\Policies;

use App\Enums\ApplicationRole;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(ApplicationRole::SuperAdmin->value)
            || $user->hasRole(ApplicationRole::Admin->value);
    }

    public function view(User $user, User $model): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if ($user->hasRole(ApplicationRole::SuperAdmin->value)) {
            return true;
        }

        return ! $model->hasRole(ApplicationRole::SuperAdmin->value);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, User $model): bool
    {
        return $this->view($user, $model);
    }

    public function delete(User $user, User $model): bool
    {
        if ($user->id === $model->id) {
            return false;
        }

        return $this->update($user, $model);
    }
}
