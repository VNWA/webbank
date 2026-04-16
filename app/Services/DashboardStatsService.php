<?php

namespace App\Services;

use App\Models\Device;
use App\Models\TransferHistory;
use App\Models\User;

class DashboardStatsService
{
    /**
     * @return array<string, int|string>
     */
    public function build(): array
    {
        return [
            'users_count' => User::query()->count(),
            'devices_count' => Device::query()->count(),
            'transfers_total_count' => TransferHistory::query()->count(),
            'transfers_pg_count' => TransferHistory::query()->where('channel', 'pg')->count(),
            'transfers_baca_count' => TransferHistory::query()->where('channel', 'baca')->count(),
            'transfers_today_count' => TransferHistory::query()->whereDate('created_at', today())->count(),
            'transfers_month_count' => TransferHistory::query()
                ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
            'transfers_volume_total' => (string) TransferHistory::query()->sum('amount'),
        ];
    }
}
