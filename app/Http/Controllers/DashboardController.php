<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\DashboardStatsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $stats = null;
        // if ($request->user()?->can('viewAny', Device::class)) {
        //     $stats = $dashboardStatsService->build();
        // }

        return Inertia::render('Dashboard', [
            'stats' => $stats,
        ]);
    }
}
