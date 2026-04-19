<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSavedTransferRecipientRequest;
use App\Models\Device;
use App\Models\SavedTransferRecipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SavedTransferRecipientController extends Controller
{
    /**
     * Lưu / cập nhật người nhận (tối đa 30 / thiết bị), gắn với `banks.id`.
     */
    public function store(StoreSavedTransferRecipientRequest $request, Device $device): JsonResponse
    {
        $validated = $request->validated();
        $accountNumber = (string) preg_replace('/\s+/', '', (string) $validated['account_number']);

        if ($accountNumber === '') {
            return response()->json([
                'message' => 'Số tài khoản không hợp lệ.',
            ], 422);
        }

        DB::transaction(function () use ($device, $validated, $accountNumber): void {
            SavedTransferRecipient::query()->updateOrCreate(
                [
                    'device_id' => $device->id,
                    'bank_id' => (int) $validated['bank_id'],
                    'account_number' => $accountNumber,
                ],
                [
                    'recipient_name' => trim((string) $validated['recipient_name']),
                    'last_used_at' => now(),
                ],
            );

            $this->pruneExcessRecipients($device);
        });

        return response()->json([
            'recipients' => SavedTransferRecipient::rowsForTransferPage($device),
        ]);
    }

    private function pruneExcessRecipients(Device $device): void
    {
        $keepIds = SavedTransferRecipient::query()
            ->where('device_id', $device->id)
            ->orderByRaw('CASE WHEN last_used_at IS NULL THEN 1 ELSE 0 END ASC')
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->limit(30)
            ->pluck('id')
            ->all();

        if ($keepIds === []) {
            return;
        }

        SavedTransferRecipient::query()
            ->where('device_id', $device->id)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }
}
