<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('device-operations', function ($user) {
    return $user->can('viewAny', \App\Models\Device::class);
});
