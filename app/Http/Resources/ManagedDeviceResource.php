<?php

namespace App\Http\Resources;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Device
 */
class ManagedDeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** Danh sách index: không gọi DWIN từng dòng — client gọi `status-batch` sau. */
        $includeLiveStatus = $request->boolean(
            'with_live_status',
            ! $request->routeIs('api.managed-devices.index'),
        );

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user_name' => $this->user?->name,
            'status' => $this->status,
            'duo_api_key' => $this->duo_api_key,
            'image_id' => $this->image_id,
            'device_status' => $includeLiveStatus ? $this->device_status : null,
            'name' => $this->name,
            'pg_pass' => $this->pg_pass,
            'pg_pin' => $this->pg_pin,
            'baca_pass' => $this->baca_pass,
            'baca_pin' => $this->baca_pin,
            'pg_video_id' => $this->pg_video_id,
            'baca_video_id' => $this->baca_video_id,
            'pg_balance' => $this->pg_balance,
            'baca_balance' => $this->baca_balance,
            'pg_balance_updated_at' => $this->pg_balance_updated_at?->toIso8601String(),
            'baca_balance_updated_at' => $this->baca_balance_updated_at?->toIso8601String(),
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
