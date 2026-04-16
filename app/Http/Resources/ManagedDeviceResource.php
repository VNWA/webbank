<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Device
 */
class ManagedDeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user_name' => $this->user?->name,
            'status' => $this->status,
            'duo_api_key' => $this->duo_api_key,
            'image_id' => $this->image_id,
            'device_status' => $this->device_status,
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
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
