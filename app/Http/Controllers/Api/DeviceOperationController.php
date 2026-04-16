<?php

namespace App\Http\Controllers\Api;

use App\Events\DeviceOperationUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDeviceOperationRequest;
use App\Jobs\ProcessDeviceOperation;
use App\Models\Device;
use App\Models\DeviceOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DeviceOperationController extends Controller
{
    public function index(Request $request, Device $device): JsonResponse
    {
        $this->authorize('view', $device);

        $operations = DeviceOperation::query()
            ->with(['logs', 'requester:id,name'])
            ->where('device_id', $device->id)
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (DeviceOperation $operation): array => $this->toArray($operation));

        return response()->json([
            'operations' => $operations,
        ]);
    }

    public function feed(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Device::class);

        $operations = DeviceOperation::query()
            ->with(['logs', 'requester:id,name'])
            ->latest('id')
            ->limit(80)
            ->get()
            ->groupBy('device_id')
            ->map(fn (Collection $items): array => $items->take(5)
                ->map(fn (DeviceOperation $operation): array => $this->toArray($operation))
                ->values()
                ->all())
            ->all();

        return response()->json([
            'operations' => $operations,
        ]);
    }

    public function store(StoreDeviceOperationRequest $request, Device $device): JsonResponse
    {
        $validated = $request->validated();

        $runningExists = DeviceOperation::query()
            ->where('device_id', $device->id)
            ->whereIn('status', ['queued', 'running'])
            ->exists();

        if ($runningExists) {
            return response()->json([
                'message' => 'Thiết bị đang chạy lệnh khác, vui lòng chờ.',
            ], 409);
        }

        $operation = DeviceOperation::query()->create([
            'device_id' => $device->id,
            'requested_by' => $request->user()->id,
            'operation_type' => $validated['operation_type'],
            'status' => 'queued',
        ]);

        ProcessDeviceOperation::dispatch($operation->id)->onQueue('devices');
        DeviceOperationUpdated::dispatch($this->toArray($operation->load(['logs', 'requester:id,name'])));

        return response()->json([
            'message' => 'Đã đưa lệnh vào hàng đợi xử lý.',
            'operation' => $this->toArray($operation->load(['logs', 'requester:id,name'])),
        ], 202);
    }

    private function toArray(DeviceOperation $operation): array
    {
        return [
            'id' => $operation->id,
            'device_id' => $operation->device_id,
            'requested_by' => $operation->requested_by,
            'requested_by_name' => $operation->requester?->name,
            'operation_type' => $operation->operation_type,
            'status' => $operation->status,
            'result_message' => $operation->result_message,
            'started_at' => $operation->started_at?->toIso8601String(),
            'finished_at' => $operation->finished_at?->toIso8601String(),
            'created_at' => $operation->created_at?->toIso8601String(),
            'logs' => $operation->logs->map(fn ($log): array => [
                'id' => $log->id,
                'level' => $log->level,
                'stage' => $log->stage,
                'message' => $log->message,
                'meta' => $log->meta,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
