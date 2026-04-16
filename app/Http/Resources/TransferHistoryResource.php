<?php

namespace App\Http\Resources;

use App\Models\TransferHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TransferHistory
 */
class TransferHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_operation_id' => $this->device_operation_id,
            'device_id' => $this->device_id,
            'device_name' => $this->whenLoaded('device', fn () => $this->device?->name),
            'device_image_id' => $this->whenLoaded('device', fn () => $this->device?->image_id),
            'channel' => $this->channel,
            'channel_label' => $this->channel === 'pg' ? 'PG Bank' : 'Bắc Á Bank',
            'bank_name' => $this->bank_name,
            'account_number' => $this->account_number,
            'recipient_name' => $this->recipient_name,
            'amount' => $this->amount,
            'transfer_note' => $this->transfer_note,
            'requested_by' => $this->requested_by,
            'requester_name' => $this->whenLoaded('requester', fn () => $this->requester?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
