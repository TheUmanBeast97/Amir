<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function show(DashboardService $dashboard): JsonResponse
    {
        return $this->ok($dashboard->build(Team::ownOrFail()));
    }
}
