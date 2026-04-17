<?php

namespace App\Http\Controllers\Api;

use App\Events\DeviceUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkDeleteManagedDevicesRequest;
use App\Http\Requests\StoreDevicePowerRequest;
use App\Http\Requests\StoreManagedDeviceRequest;
use App\Http\Requests\UpdateDeviceNoteRequest;
use App\Http\Requests\UpdateManagedDeviceRequest;
use App\Http\Resources\ManagedDeviceResource;
use App\Models\Device;
use App\Services\BankLookupApi;
use App\Services\DuoPlusApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class ManagedDeviceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Device::class);

        $perPage = min(max((int) $request->integer('per_page', 10), 5), 100);
        $search = $request->string('search')->trim()->value();

        $query = Device::query()
            ->with('user:id,name')
            ->orderByDesc('id');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('duo_api_key', 'like', '%'.$search.'%')
                    ->orWhere('image_id', 'like', '%'.$search.'%');
            });
        }

        return ManagedDeviceResource::collection($query->paginate($perPage));
    }

    /**
     * Trạng thái máy ảo DWIN theo batch (gom theo `duo_api_key`, ít request hơn so với gọi khi render từng dòng).
     *
     * @return JsonResponse array{statuses: array<string, string>}
     */
    public function statusBatch(Request $request, DuoPlusApi $duoPlusApi): JsonResponse
    {
        $this->authorize('viewAny', Device::class);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:50'],
            'ids.*' => ['integer'],
        ]);

        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $validated['ids']),
            static fn (int $id): bool => $id > 0,
        )));

        $devices = Device::query()->whereIn('id', $ids)->get();

        foreach ($devices as $device) {
            $this->authorize('view', $device);
        }

        $mapByDeviceId = [];
        $byKey = $devices->groupBy('duo_api_key');

        foreach ($byKey as $apiKey => $group) {
            $key = (string) $apiKey;
            if ($key === '') {
                foreach ($group as $d) {
                    $mapByDeviceId[$d->id] = 'unknown';
                }

                continue;
            }

            $imageIds = $group->pluck('image_id')->map(fn ($id) => (string) $id)->unique()->values()->all();
            $labelsByImage = $duoPlusApi->liveDeviceStatusLabelsForImages($key, $imageIds);

            foreach ($group as $device) {
                $img = (string) $device->image_id;
                $mapByDeviceId[$device->id] = $labelsByImage[$img] ?? 'unknown';
            }
        }

        $out = [];
        foreach ($ids as $id) {
            $out[(string) $id] = $mapByDeviceId[$id] ?? 'unknown';
        }

        return response()->json(['statuses' => $out]);
    }

    public function store(StoreManagedDeviceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $device = Device::query()->create([
            ...$data,
            'user_id' => $request->user()->id,
            'name' => $data['name'] ?? ('Device '.$data['image_id']),
            'status' => $data['status'] ?? 'normal',
        ]);

        return ManagedDeviceResource::make($device->load('user:id,name'))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateManagedDeviceRequest $request, Device $device): ManagedDeviceResource
    {
        $device->update($request->validated());

        return ManagedDeviceResource::make($device->fresh()->load('user:id,name'));
    }

    public function updateNote(UpdateDeviceNoteRequest $request, Device $device): ManagedDeviceResource
    {
        $note = $request->validated('note');
        $normalized = is_string($note) && trim($note) !== '' ? trim($note) : null;

        $device->update(['note' => $normalized]);

        return ManagedDeviceResource::make($device->fresh()->load('user:id,name'));
    }

    public function fetchDuoPlusInfo(Request $request): JsonResponse
    {
        $this->authorize('create', Device::class);

        $data = $request->validate([
            'duo_api_key' => ['required', 'string', 'max:255'],
            'image_id' => ['required', 'string', 'max:255'],
        ]);

        $response = Http::timeout(15)
            ->acceptJson()
            ->withHeaders([
                'Lang' => 'en',
                'DuoPlus-API-Key' => $data['duo_api_key'],
            ])
            ->asJson()
            ->post('https://openapi.duoplus.net/api/v1/cloudPhone/info', [
                'image_id' => $data['image_id'],
            ]);

        if (! $response->successful() || (int) data_get($response->json(), 'code') !== 200) {
            return response()->json([
                'message' => 'Không thể lấy thông tin từ DuoPlus.',
                'details' => $response->json(),
            ], 422);
        }

        $payload = $response->json();
        $cloudPhone = $this->resolveCloudPhonePayload($payload, $data['image_id']);

        $resolved = [
            'name' => data_get($cloudPhone, 'name', 'Device '.$data['image_id']),
            'status' => data_get($cloudPhone, 'status') === 'pending' ? 'pending' : 'normal',
            'device_status' => in_array(data_get($cloudPhone, 'device_status'), ['on', 'off'], true)
                ? data_get($cloudPhone, 'device_status')
                : (in_array(data_get($cloudPhone, 'status'), ['on', 'off'], true) ? data_get($cloudPhone, 'status') : 'off'),
            'pg_video_id' => data_get($cloudPhone, 'pg_video_id', ''),
            'baca_video_id' => data_get($cloudPhone, 'baca_video_id', ''),
        ];

        return response()->json([
            'raw' => $payload,
            'resolved' => $resolved,
        ]);
    }

    public function fetchDuoPlusFiles(Request $request): JsonResponse
    {
        $this->authorize('create', Device::class);

        $data = $request->validate([
            'duo_api_key' => ['required', 'string', 'max:255'],
            'keyword' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'pagesize' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $result = $this->fetchCloudDiskFiles(
            dwinKey: $data['duo_api_key'],
            keyword: (string) ($data['keyword'] ?? ''),
            page: (int) ($data['page'] ?? 1),
            pageSize: (int) ($data['pagesize'] ?? 20),
        );

        return response()->json([
            'files' => $result['files'],
            'pagination' => $result['pagination'],
        ]);
    }

    public function listBankLookupBanks(Request $request, BankLookupApi $bankLookupApi): JsonResponse
    {
        $this->authorize('viewAny', Device::class);

        $result = $bankLookupApi->listBanks();

        return response()->json([
            'message' => $result['message'],
            'data' => [
                'ok' => $result['ok'],
                'banks' => $result['banks'],
            ],
        ], $result['ok'] ? 200 : 422);
    }

    public function lookupBankLookupAccountName(Request $request, BankLookupApi $bankLookupApi): JsonResponse
    {
        $this->authorize('viewAny', Device::class);

        $data = $request->validate([
            'bank' => ['required', 'string', 'max:50'],
            'account' => ['required', 'string', 'max:50'],
        ]);

        $result = $bankLookupApi->lookupAccountName($data['bank'], $data['account']);

        return response()->json([
            'message' => $result['message'],
            'data' => $result,
        ], $result['ok'] ? 200 : 422);
    }

    /**
     * Bật/tắt máy trực tiếp qua DWIN (không queue) — tách khỏi luồng lệnh dài.
     */
    public function power(StoreDevicePowerRequest $request, Device $device, DuoPlusApi $duoPlusApi): JsonResponse
    {
        $this->authorize('update', $device);

        $action = $request->validated()['action'];
        $live = $duoPlusApi->liveDeviceStatusLabel($device->duo_api_key, $device->image_id);

        if ($action === 'on' && in_array($live, ['on', 'powering_on'], true)) {
            $payload = ManagedDeviceResource::make($device->load('user:id,name'))->resolve();
            DeviceUpdated::dispatch($payload);

            return response()->json([
                'message' => $live === 'powering_on' ? 'Máy đang trong trạng thái bật nguồn.' : 'Máy đang bật.',
                'data' => $payload,
            ]);
        }

        if ($action === 'off' && $live === 'off') {
            $payload = ManagedDeviceResource::make($device->load('user:id,name'))->resolve();
            DeviceUpdated::dispatch($payload);

            return response()->json([
                'message' => 'Máy đang tắt.',
                'data' => $payload,
            ]);
        }

        $result = $action === 'on'
            ? $duoPlusApi->powerOn($device->duo_api_key, $device->image_id)
            : $duoPlusApi->powerOff($device->duo_api_key, $device->image_id);

        if (! $result['ok']) {
            return response()->json([
                'message' => $result['message'] !== '' ? $result['message'] : 'DWIN không chấp nhận thao tác power.',
            ], 422);
        }

        $payload = ManagedDeviceResource::make($device->fresh()->load('user:id,name'))->resolve();
        DeviceUpdated::dispatch($payload);

        return response()->json([
            'message' => $action === 'on' ? 'Đã gửi bật máy tới DuoPlus.' : 'Đã gửi tắt máy tới DuoPlus.',
            'data' => $payload,
        ]);
    }

    public function destroy(Request $request, Device $device): JsonResponse
    {
        $this->authorize('delete', $device);
        $device->delete();

        return response()->json([], 204);
    }

    public function bulkDestroy(BulkDeleteManagedDevicesRequest $request): JsonResponse
    {
        $ids = $request->validated()['ids'];

        Device::query()
            ->whereIn('id', $ids)
            ->delete();

        return response()->json([], 204);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function resolveCloudPhonePayload(array $payload, string $imageId): array
    {
        $data = data_get($payload, 'data');

        if (is_array($data) && Arr::isAssoc($data)) {
            return $data;
        }

        if (is_array($data) && ! Arr::isAssoc($data)) {
            foreach ($data as $item) {
                if (is_array($item) && (string) data_get($item, 'image_id') === $imageId) {
                    return $item;
                }
            }

            return $data[0] ?? [];
        }

        return [];
    }

    /**
     * @return array{
     *   files: list<array{id: string, name: string, original_file_name: string}>,
     *   pagination: array{page: int, pagesize: int, total: int, total_page: int, has_more: bool}
     * }
     */
    private function fetchCloudDiskFiles(string $dwinKey, string $keyword = '', int $page = 1, int $pageSize = 20): array
    {
        $response = Http::timeout(15)
            ->acceptJson()
            ->withHeaders([
                'Lang' => 'en',
                'DuoPlus-API-Key' => $dwinKey,
            ])
            ->asJson()
            ->post('https://openapi.duoplus.net/api/v1/cloudDisk/list', [
                'keyword' => $keyword,
                'page' => max($page, 1),
                'pagesize' => min(max($pageSize, 1), 100),
            ]);

        if (! $response->successful() || (int) data_get($response->json(), 'code') !== 200) {
            return [
                'files' => [],
                'pagination' => [
                    'page' => 1,
                    'pagesize' => 0,
                    'total' => 0,
                    'total_page' => 0,
                    'has_more' => false,
                ],
            ];
        }

        $payload = $response->json();
        $list = data_get($payload, 'data.list', []);
        if (! is_array($list)) {
            return [
                'files' => [],
                'pagination' => [
                    'page' => (int) data_get($payload, 'data.page', 1),
                    'pagesize' => (int) data_get($payload, 'data.pagesize', 0),
                    'total' => (int) data_get($payload, 'data.total', 0),
                    'total_page' => (int) data_get($payload, 'data.total_page', 0),
                    'has_more' => false,
                ],
            ];
        }

        $files = collect($list)
            ->filter(fn ($item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'id' => (string) data_get($item, 'id', ''),
                'name' => (string) data_get($item, 'name', ''),
                'original_file_name' => (string) data_get($item, 'original_file_name', data_get($item, 'name', '')),
            ])
            ->filter(fn (array $item): bool => $item['id'] !== '' && $item['name'] !== '')
            ->filter(fn (array $item): bool => str_ends_with(strtolower($item['name']), '.mp4')
                || str_ends_with(strtolower($item['original_file_name']), '.mp4'))
            ->values()
            ->all();

        $currentPage = (int) data_get($payload, 'data.page', 1);
        $totalPage = (int) data_get($payload, 'data.total_page', 0);

        return [
            'files' => $files,
            'pagination' => [
                'page' => $currentPage,
                'pagesize' => (int) data_get($payload, 'data.pagesize', 0),
                'total' => (int) data_get($payload, 'data.total', 0),
                'total_page' => $totalPage,
                'has_more' => $totalPage > 0 && $currentPage < $totalPage,
            ],
        ];
    }
}
