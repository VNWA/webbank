<?php

namespace App\Models;

use App\Services\DuoPlusApi;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'status',
        'duo_api_key',
        'image_id',
        'name',
        'pg_pass',
        'pg_pin',
        'baca_pass',
        'baca_pin',
        'pg_video_id',
        'baca_video_id',
        'pg_balance',
        'baca_balance',
        'pg_balance_updated_at',
        'baca_balance_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'pg_balance' => 'decimal:2',
            'baca_balance' => 'decimal:2',
            'pg_balance_updated_at' => 'datetime',
            'baca_balance_updated_at' => 'datetime',
        ];
    }

    /**
     * Mỗi lần serialize / đọc thuộc tính sẽ gọi DWIN Cloud Phone Status (không lưu DB).
     *
     * @see https://help.duoplus.net/docs/cloud-phone-status
     */
    protected $appends = [
        'device_status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(DeviceOperation::class)->latest('id');
    }

    public function transferHistories(): HasMany
    {
        return $this->hasMany(TransferHistory::class)->latest('id');
    }

    /**
     * Nhãn trạng thái nguồn từ DWIN (`on`, `off`, `unknown`, …).
     */
    protected function deviceStatus(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                return app(DuoPlusApi::class)->liveDeviceStatusLabel(
                    (string) $this->duo_api_key,
                    (string) $this->image_id,
                );
            },
        );
    }
}
