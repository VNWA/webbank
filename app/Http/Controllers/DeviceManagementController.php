<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DeviceManagementController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Device::class);

        return Inertia::render('Devices/Index', [
            'statusOptions' => ['normal', 'pending'],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Device::class);

        return Inertia::render('Devices/Create', [
            'statusOptions' => ['normal', 'pending'],
        ]);
    }

    public function edit(Request $request, Device $device): Response
    {
        $this->authorize('update', $device);

        return Inertia::render('Devices/Edit', [
            'device' => $device->only([
                'id',
                'name',
                'duo_api_key',
                'image_id',
                'status',
                'pg_pass',
                'pg_pin',
                'baca_pass',
                'baca_pin',
                'pg_video_id',
                'baca_video_id',
            ]),
        ]);
    }
}
