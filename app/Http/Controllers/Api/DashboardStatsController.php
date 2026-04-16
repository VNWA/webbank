<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\DashboardStatsService;
use Illuminate\Http\JsonResponse;

class DashboardStatsController extends Controller
{
    public function __invoke(DashboardStatsService $dashboardStatsService): JsonResponse
    {
        $this->authorize('viewAny', Device::class);

        return response()->json([
            'data' => $dashboardStatsService->build(),
        ]);
    }
}
