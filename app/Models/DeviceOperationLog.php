<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceOperationLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_operation_id',
        'level',
        'stage',
        'message',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(DeviceOperation::class, 'device_operation_id');
    }
}
