<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bank extends Model
{
    protected $fillable = [
        'code',
        'name',
        'short_name',
        'pg_name',
        'baca_name',
    ];

    /**
     * Chuỗi gõ tìm NH trên app (đồng bộ với `Transfer.vue` / `DeviceOperationController`).
     */
    public function appSearchLabelForOperation(string $operationType): string
    {
        $raw = $operationType === 'pg_transfer'
            ? trim((string) $this->pg_name)
            : trim((string) $this->baca_name);

        if ($raw !== '') {
            $first = trim(explode(' | ', $raw, 2)[0] ?? '');
            if ($first !== '') {
                return $first;
            }
        }

        return (string) $this->name;
    }
}
