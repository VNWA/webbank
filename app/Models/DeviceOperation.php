<?php

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceOperation extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'requested_by',
        'operation_type',
        'operation_payload',
        'status',
        'result_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'operation_payload' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(DeviceOperationLog::class)->orderBy('id');
    }

    /**
     * Payload gọn để broadcast realtime (tránh "Payload too large").
     *
     * @return array<string, mixed>
     */
    public function toBroadcastArray(): array
    {
        $logs = $this->relationLoaded('logs') ? $this->logs : $this->logs()->get();

        $device = $this->relationLoaded('device') ? $this->device : $this->device()->first();

        $payload = [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'requested_by' => $this->requested_by,
            'requested_by_name' => $this->relationLoaded('requester') ? $this->requester?->name : null,
            'operation_type' => $this->operation_type,
            'status' => $this->status,
            'result_message' => $this->result_message ? Str::limit($this->result_message, 900) : null,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'device_balances' => $device ? [
                'pg_balance' => $device->pg_balance,
                'baca_balance' => $device->baca_balance,
                'pg_balance_updated_at' => $device->pg_balance_updated_at,
                'baca_balance_updated_at' => $device->baca_balance_updated_at,
            ] : null,
            'logs' => $logs
                ->sortBy('id')
                ->take(22)
                ->map(fn (DeviceOperationLog $log): array => [
                    'id' => $log->id,
                    'level' => $log->level,
                    'stage' => $log->stage,
                    'message' => Str::limit($log->message, 500),
                    // Không broadcast meta nặng (trace/xml...) để tránh vượt giới hạn.
                    'meta' => null,
                    'created_at' => $log->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];

        return $this->ensureBroadcastPayloadFits($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function ensureBroadcastPayloadFits(array $payload): array
    {
        $encoded = json_encode($payload);
        if (! is_string($encoded)) {
            return $payload;
        }

        // Pusher/Reverb thường giới hạn ~10KB/event; dùng ngưỡng an toàn 9KB.
        if (strlen($encoded) < 9_216) {
            return $payload;
        }

        $payload['logs'] = array_slice((array) ($payload['logs'] ?? []), 0, 12);
        $payload['result_message'] = $payload['result_message'] ? Str::limit((string) $payload['result_message'], 320) : null;

        return $payload;
    }
}
