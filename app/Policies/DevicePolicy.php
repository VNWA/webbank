<?php

namespace App\Policies;

use App\Enums\ApplicationRole;
use App\Models\Device;
use App\Models\User;

class DevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(ApplicationRole::SuperAdmin->value)
            || $user->hasRole(ApplicationRole::Admin->value);
    }

    public function view(User $user, Device $device): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Device $device): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Device $device): bool
    {
        return $this->viewAny($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->viewAny($user);
    }
}
