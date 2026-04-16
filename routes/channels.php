<?php

use App\Models\Device;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('device-operations', function ($user) {
    return $user->can('viewAny', Device::class);
});

Broadcast::channel('devices', function ($user) {
    return $user->can('viewAny', Device::class);
});
