<?php

namespace App\Http\Controllers\Api;

use App\Actions\FieldActivities\RecordFieldActivity;
use App\Http\Controllers\Controller;
use App\Models\Farmer;
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
            ->with(['crop', 'recommendations.product'])
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

    public function store(Request $request, RecordFieldActivity $recorder): JsonResponse
    {
        $employee = $request->user()->employee;

        $validated = $request->validate([
            'farmer_name' => ['required', 'string', 'max:255'],
            'farmer_mobile' => ['required', 'string', 'regex:'.Farmer::MOBILE_REGEX],
            'district_id' => ['required', 'integer', 'exists:maharashtra_districts,id'],
            'taluka_id' => ['required', 'integer', 'exists:maharashtra_talukas,id'],
            'village' => ['required', 'string', 'max:255'],
            'crop_id' => ['required', 'integer', 'exists:crops,id'],
            'remark' => ['nullable', 'string', 'max:2000'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'recommendations' => ['required', 'array', 'min:1'],
            'recommendations.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'recommendations.*.dosage' => ['nullable', 'string', 'max:255'],
            'recommendations.*.remark' => ['nullable', 'string', 'max:2000'],
        ]);

        $activity = $recorder->execute(
            $employee,
            $validated,
            $validated['recommendations'],
            $request->file('photo'),
        );

        return response()->json([
            'message' => 'Field activity submitted successfully.',
            'data' => $this->formatDetail($activity),
        ], 201);
    }

    public function show(Request $request, FieldActivity $fieldActivity): JsonResponse
    {
        $this->authorizeFieldActivity($request, $fieldActivity);
        $fieldActivity->load([
            'employee:id,full_name',
            'crop',
            'farmer.district',
            'farmer.taluka',
            'recommendations.product',
            'recommendations.crop',
        ]);

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

    /**
     * @return array<string, mixed>
     */
    private function formatListItem(FieldActivity $activity): array
    {
        return [
            'id' => $activity->id,
            'farmer_name' => $activity->farmer_name,
            'farmer_mobile' => $activity->farmer_mobile,
            'village' => $activity->village,
            'taluka' => $activity->taluka,
            'district' => $activity->district,
            'crop_name' => $activity->crop?->name,
            'activity_date' => $activity->activity_date->toDateString(),
            'activity_time' => $this->formatActivityTime($activity),
            'status' => $activity->status,
            'photo_url' => $activity->photoUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatDetail(FieldActivity $activity): array
    {
        $activity->loadMissing([
            'employee:id,full_name',
            'crop',
            'recommendations.product',
            'recommendations.crop',
        ]);

        return [
            'id' => $activity->id,
            'farmer_id' => $activity->farmer_id,
            'farmer_name' => $activity->farmer_name,
            'farmer_mobile' => $activity->farmer_mobile,
            'village' => $activity->village,
            'taluka' => $activity->taluka,
            'district' => $activity->district,
            'district_id' => $activity->district_id,
            'taluka_id' => $activity->taluka_id,
            'crop_id' => $activity->crop_id,
            'crop_name' => $activity->crop?->name,
            'remark' => $activity->remark,
            'activity_type' => $activity->activity_type,
            'activity_date' => $activity->activity_date->toDateString(),
            'activity_time' => $this->formatActivityTime($activity),
            'photo_url' => $activity->photoUrl(),
            'latitude' => $activity->latitude,
            'longitude' => $activity->longitude,
            'maps_url' => $activity->mapsUrl(),
            'employee_name' => $activity->employee?->full_name,
            'employee_id' => $activity->employee_id,
            'status' => $activity->status,
            'status_label' => FieldActivity::statusLabel($activity->status),
            'recommendations' => $activity->recommendations
                ->map(fn ($row): array => $row->toApiArray())
                ->values()
                ->all(),
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
