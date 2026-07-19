<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FieldActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class EmployeeFieldActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        $today = FieldActivity::businessToday();
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $weekEnd = $today->copy()->endOfWeek(Carbon::MONDAY)->toDateString();
        $monthStart = $today->copy()->startOfMonth()->toDateString();
        $monthEnd = $today->copy()->endOfMonth()->toDateString();

        $activities = FieldActivity::query()->where('employee_id', $employee->id);

        $summary = [
            'total_activities' => (clone $activities)->count(),
            'month_activities' => (clone $activities)
                ->whereBetween('activity_date', [$monthStart, $monthEnd])
                ->count(),
            'week_activities' => (clone $activities)
                ->whereBetween('activity_date', [$weekStart, $weekEnd])
                ->count(),
            'today_activities' => (clone $activities)
                ->whereDate('activity_date', $today->toDateString())
                ->count(),
        ];

        $recentActivities = (clone $activities)
            ->orderByDesc('activity_date')
            ->orderByDesc('activity_time')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (FieldActivity $activity): array => $this->formatListItem($activity))
            ->values();

        return response()->json([
            'summary' => $summary,
            'recent_activities' => $recentActivities,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        $validated = $request->validate([
            'farmer_name' => ['required', 'string', 'max:255'],
            'village' => ['required', 'string', 'max:255'],
            'taluka' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $now = FieldActivity::businessNow();
        $photoPath = str_replace('\\', '/', $request->file('photo')->store('field-activities', 'public'));

        $activity = FieldActivity::query()->create([
            'employee_id' => $employee->id,
            'farmer_name' => trim($validated['farmer_name']),
            'village' => trim($validated['village']),
            'taluka' => trim($validated['taluka']),
            'activity_date' => $now->toDateString(),
            'activity_time' => $now->format('H:i:s'),
            'photo_path' => $photoPath,
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'status' => FieldActivity::STATUS_COMPLETED,
        ]);

        $activity->load('employee:id,full_name');

        return response()->json([
            'message' => 'Field activity submitted successfully.',
            'data' => $this->formatDetail($activity),
        ], 201);
    }

    public function show(Request $request, FieldActivity $fieldActivity): JsonResponse
    {
        $this->authorizeFieldActivity($request, $fieldActivity);
        $fieldActivity->load('employee:id,full_name');

        return response()->json([
            'data' => $this->formatDetail($fieldActivity),
        ]);
    }

    private function authorizeFieldActivity(Request $request, FieldActivity $fieldActivity): void
    {
        if ($fieldActivity->employee_id !== $request->user()->employee->id) {
            abort(403, 'You are not allowed to access this field activity.');
        }
    }

    private function formatListItem(FieldActivity $activity): array
    {
        return [
            'id' => $activity->id,
            'farmer_name' => $activity->farmer_name,
            'village' => $activity->village,
            'taluka' => $activity->taluka,
            'activity_date' => $activity->activity_date->toDateString(),
            'activity_time' => $this->formatActivityTime($activity),
            'status' => $activity->status,
            'photo_url' => $activity->photoUrl(),
        ];
    }

    private function formatDetail(FieldActivity $activity): array
    {
        return [
            'id' => $activity->id,
            'farmer_name' => $activity->farmer_name,
            'village' => $activity->village,
            'taluka' => $activity->taluka,
            'activity_date' => $activity->activity_date->toDateString(),
            'activity_time' => $this->formatActivityTime($activity),
            'photo_url' => $activity->photoUrl(),
            'latitude' => $activity->latitude,
            'longitude' => $activity->longitude,
            'employee_name' => $activity->employee?->full_name,
            'status' => $activity->status,
            'status_label' => FieldActivity::statusLabel($activity->status),
        ];
    }

    private function formatActivityTime(FieldActivity $activity): string
    {
        if (filled($activity->activity_time)) {
            return Carbon::parse((string) $activity->activity_time)->format('H:i');
        }

        return $activity->created_at
            ?->timezone('Asia/Kolkata')
            ->format('H:i') ?? '-';
    }
}
