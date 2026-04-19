<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedTransferRecipient extends Model
{
    protected $fillable = [
        'device_id',
        'bank_id',
        'account_number',
        'recipient_name',
        'last_used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /**
     * Danh sách tối đa 30 bản ghi / thiết bị, mới dùng trước (đồng bộ với trang Inertia chuyển tiền).
     *
     * @return list<array<string, mixed>>
     */
    public static function rowsForTransferPage(Device $device): array
    {
        return self::query()
            ->where('device_id', $device->id)
            ->with('bank:id,code,name')
            ->orderByRaw('CASE WHEN last_used_at IS NULL THEN 1 ELSE 0 END ASC')
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (self $row): array => $row->toTransferPageRow())
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toTransferPageRow(): array
    {
        $this->loadMissing('bank:id,code,name');
        $bank = $this->bank;

        return [
            'id' => $this->id,
            'bank_id' => $this->bank_id,
            'bank_code' => $bank !== null ? (string) $bank->code : '',
            'label' => ($bank !== null ? (string) $bank->name : 'Ngân hàng').' • '.$this->account_number,
            'bank_name' => $bank !== null ? (string) $bank->name : '',
            'account_number' => $this->account_number,
            'recipient_name' => $this->recipient_name,
            'last_used_at' => $this->last_used_at?->toIso8601String() ?? '',
        ];
    }
}
