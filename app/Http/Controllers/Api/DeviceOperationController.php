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

        if ($this->isBlockedRecipientBank($validated['operation_type'], $validated['operation_payload'] ?? null)) {
            return response()->json([
                'message' => 'Không thể chuyển cùng ngân hàng với kênh đã chọn.',
            ], 422);
        }

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
            'operation_payload' => $validated['operation_payload'] ?? null,
            'status' => 'queued',
        ]);

        ProcessDeviceOperation::dispatch($operation->id)->onQueue('devices');
        DeviceOperationUpdated::dispatch($operation->load(['logs', 'requester:id,name'])->toBroadcastArray());

        return response()->json([
            'message' => 'Đã đưa lệnh vào hàng đợi xử lý.',
            'operation' => $this->toArray($operation->load(['logs', 'requester:id,name'])),
        ], 202);
    }

    /**
     * Chặn chuyển cùng bank theo kênh:
     * - pg_transfer không được chuyển tới PG Bank
     * - baca_transfer không được chuyển tới Bắc Á
     */
    private function isBlockedRecipientBank(string $operationType, mixed $payload): bool
    {
        if (! in_array($operationType, ['pg_transfer', 'baca_transfer'], true)) {
            return false;
        }

        if (! is_array($payload)) {
            return false;
        }

        $code = strtoupper((string) ($payload['bank_code'] ?? ''));
        $name = strtoupper((string) ($payload['bank_name'] ?? ''));
        $name = preg_replace('/[^A-Z0-9]+/', '', $name) ?? $name;

        if ($operationType === 'pg_transfer') {
            return $code === 'PGBANK' || str_contains($name, 'PGBANK') || str_contains($name, 'PGBANK');
        }

        // baca_transfer
        return $code === 'BACABANK' || str_contains($name, 'BACABANK') || str_contains($name, 'BACA');
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
