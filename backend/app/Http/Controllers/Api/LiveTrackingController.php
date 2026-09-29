<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\LiveTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveTrackingController extends Controller
{
    public function __construct(
        private readonly LiveTrackingService $liveTracking,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->liveTracking->snapshot($request->user()));
    }

    public function route(Request $request, Employee $employee): JsonResponse
    {
        $afterPointId = $request->query('after_point_id');

        return response()->json($this->liveTracking->routeForEmployee(
            $request->user(),
            (int) $employee->id,
            filled($afterPointId) ? (int) $afterPointId : null,
        ));
    }
}
