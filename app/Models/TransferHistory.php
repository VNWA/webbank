<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferHistory extends Model
{
    protected $fillable = [
        'device_operation_id',
        'device_id',
        'channel',
        'bank_name',
        'account_number',
        'recipient_name',
        'amount',
        'transfer_note',
        'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
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

    public function deviceOperation(): BelongsTo
    {
        return $this->belongsTo(DeviceOperation::class);
    }
}
