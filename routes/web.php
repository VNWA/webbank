<?php

use App\Http\Controllers\Api\ManagedDeviceController;
use App\Http\Controllers\Api\DeviceOperationController;
use App\Http\Controllers\Api\ManagedUserController;
use App\Http\Controllers\DeviceManagementController;
use App\Http\Controllers\UserManagementController;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome', [
    // Disable public user registration (users are created via Manager User).
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('user-management', [UserManagementController::class, 'index'])
        ->name('user-management.index')
        ->can('viewAny', User::class);
    Route::get('device-management', [DeviceManagementController::class, 'index'])
        ->name('device-management.index')
        ->can('viewAny', Device::class);
    Route::get('device-management/create', [DeviceManagementController::class, 'create'])
        ->name('device-management.create')
        ->can('create', Device::class);
    Route::get('device-management/{device}/edit', [DeviceManagementController::class, 'edit'])
        ->name('device-management.edit')
        ->can('update', 'device');
    Route::get('device-management/{device}/transfer', [DeviceManagementController::class, 'transfer'])
        ->name('device-management.transfer')
        ->can('update', 'device');

    Route::prefix('api')->group(function () {
        Route::get('managed-users', [ManagedUserController::class, 'index'])
            ->name('api.managed-users.index')
            ->can('viewAny', User::class);
        Route::post('managed-users', [ManagedUserController::class, 'store'])
            ->name('api.managed-users.store')
            ->can('create', User::class);
        Route::put('managed-users/{user}', [ManagedUserController::class, 'update'])
            ->name('api.managed-users.update')
            ->can('update', 'user');
        Route::delete('managed-users/{user}', [ManagedUserController::class, 'destroy'])
            ->name('api.managed-users.destroy')
            ->can('delete', 'user');

        Route::get('managed-devices', [ManagedDeviceController::class, 'index'])
            ->name('api.managed-devices.index')
            ->can('viewAny', Device::class);
        Route::post('managed-devices', [ManagedDeviceController::class, 'store'])
            ->name('api.managed-devices.store')
            ->can('create', Device::class);
        Route::post('managed-devices/duoplus-info', [ManagedDeviceController::class, 'fetchDuoPlusInfo'])
            ->name('api.managed-devices.duoplus-info')
            ->can('create', Device::class);
        Route::post('managed-devices/duoplus-files', [ManagedDeviceController::class, 'fetchDuoPlusFiles'])
            ->name('api.managed-devices.duoplus-files')
            ->can('create', Device::class);
        Route::get('managed-devices/banklookup/banks', [ManagedDeviceController::class, 'listBankLookupBanks'])
            ->name('api.managed-devices.banklookup.banks')
            ->can('viewAny', Device::class);
        Route::post('managed-devices/banklookup/account-name', [ManagedDeviceController::class, 'lookupBankLookupAccountName'])
            ->name('api.managed-devices.banklookup.account-name')
            ->can('viewAny', Device::class);
        Route::put('managed-devices/{device}', [ManagedDeviceController::class, 'update'])
            ->name('api.managed-devices.update')
            ->can('update', 'device');
        Route::post('managed-devices/{device}/power', [ManagedDeviceController::class, 'power'])
            ->name('api.managed-devices.power')
            ->can('update', 'device');
        Route::get('managed-devices/{device}/operations', [DeviceOperationController::class, 'index'])
            ->name('api.managed-devices.operations.index')
            ->can('view', 'device');
        Route::get('managed-device-operations/feed', [DeviceOperationController::class, 'feed'])
            ->name('api.managed-devices.operations.feed')
            ->can('viewAny', Device::class);
        Route::post('managed-devices/{device}/operations', [DeviceOperationController::class, 'store'])
            ->name('api.managed-devices.operations.store')
            ->can('update', 'device');
        Route::delete('managed-devices/{device}', [ManagedDeviceController::class, 'destroy'])
            ->name('api.managed-devices.destroy')
            ->can('delete', 'device');
        Route::delete('managed-devices', [ManagedDeviceController::class, 'bulkDestroy'])
            ->name('api.managed-devices.bulk-destroy')
            ->can('deleteAny', Device::class);
    });
});

require __DIR__.'/settings.php';
