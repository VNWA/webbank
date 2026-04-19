<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\Device;
use App\Models\SavedTransferRecipient;
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

    public function transfer(Request $request, Device $device): Response
    {
        $this->authorize('update', $device);

        return Inertia::render('Devices/Transfer', [
            'device' => $device->only([
                'id',
                'name',
                'image_id',
            ]),
            'banks' => Bank::query()
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'short_name', 'pg_name', 'baca_name'])
                ->map(fn (Bank $b): array => [
                    'id' => $b->id,
                    'code' => $b->code,
                    'name' => $b->name,
                    'short_name' => $b->short_name,
                    'pg_name' => $b->pg_name,
                    'baca_name' => $b->baca_name,
                ])
                ->values()
                ->all(),
            'savedRecipients' => SavedTransferRecipient::rowsForTransferPage($device),
        ]);
    }
}
