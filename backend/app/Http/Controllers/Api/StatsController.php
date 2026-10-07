<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Stats\AttendanceStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    /** ?season=2026/2027 (default: stagione in corso) oppure ?season=all */
    public function attendance(Request $request, AttendanceStats $stats): JsonResponse
    {
        $request->validate(['season' => ['nullable', 'string', 'max:9']]);

        $season = $request->query('season', config('amir.xfive.current_season'));
        $season = $season === 'all' ? null : $season;

        return $this->ok($stats->leaderboard(Team::ownOrFail(), $season));
    }
}
