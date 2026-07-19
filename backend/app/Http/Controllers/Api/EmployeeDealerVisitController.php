<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Models\DealerVisit;
use App\Services\Dealers\DealerAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeDealerVisitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        $today = DealerVisit::businessToday();
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $weekEnd = $today->copy()->endOfWeek(Carbon::MONDAY)->toDateString();

        $visits = DealerVisit::query()->where('employee_id', $employee->id);

        $summary = [
            'total_visits' => (clone $visits)->count(),
            'week_visits' => (clone $visits)
                ->whereBetween('visit_date', [$weekStart, $weekEnd])
                ->count(),
            'today_visits' => (clone $visits)
                ->whereDate('visit_date', $today->toDateString())
                ->count(),
        ];

        $recentVisits = (clone $visits)
            ->with('dealer:id,firm_name')
            ->orderByDesc('visit_date')
            ->orderByDesc('visit_time')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (DealerVisit $visit): array => $this->formatListItem($visit))
            ->values();

        return response()->json([
            'summary' => $summary,
            'recent_visits' => $recentVisits,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        $validated = $request->validate([
            'dealer_id' => [
                'required',
                'integer',
                Rule::exists('dealers', 'id')->where(fn ($query) => $query->where('status', true)),
            ],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'min:0'],
            'location_captured_at' => ['required', 'date'],
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $dealer = Dealer::query()
            ->whereKey($validated['dealer_id'])
            ->where('status', true)
            ->first();

        if (
            $dealer === null
            || ! app(DealerAccessService::class)->employeeCanAccessDealer($employee->id, $dealer)
        ) {
            throw ValidationException::withMessages([
                'dealer_id' => 'Selected dealer is not available.',
            ]);
        }

        $this->assertValidCoordinates(
            (float) $validated['latitude'],
            (float) $validated['longitude'],
        );

        $locationCapturedAt = Carbon::parse($validated['location_captured_at'])
            ->timezone('Asia/Kolkata');

        $this->assertFreshLocationCapture($locationCapturedAt);

        $now = DealerVisit::businessNow();
        $photoPath = str_replace('\\', '/', $request->file('photo')->store('dealer-visits', 'public'));

        $visit = DealerVisit::query()->create([
            'employee_id' => $employee->id,
            'dealer_id' => $validated['dealer_id'],
            'visit_date' => $now->toDateString(),
            'visit_time' => $now->format('H:i:s'),
            'photo_path' => $photoPath,
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'accuracy' => $validated['accuracy'],
            'location_captured_at' => $locationCapturedAt,
            'status' => DealerVisit::STATUS_COMPLETED,
        ]);

        $visit->load([
            'employee:id,full_name',
            'dealer:id,firm_name,owner_name,village',
        ]);

        return response()->json([
            'message' => 'Dealer visit submitted successfully.',
            'data' => $this->formatDetail($visit),
        ], 201);
    }

    public function show(Request $request, DealerVisit $dealerVisit): JsonResponse
    {
        $this->authorizeDealerVisit($request, $dealerVisit);
        $dealerVisit->load([
            'employee:id,full_name',
            'dealer:id,firm_name,owner_name,village',
        ]);

        return response()->json([
            'data' => $this->formatDetail($dealerVisit),
        ]);
    }

    private function authorizeDealerVisit(Request $request, DealerVisit $dealerVisit): void
    {
        if ($dealerVisit->employee_id !== $request->user()->employee->id) {
            abort(403, 'You are not allowed to access this dealer visit.');
        }
    }

    private function assertValidCoordinates(float $latitude, float $longitude): void
    {
        if (abs($latitude) < 0.000001 && abs($longitude) < 0.000001) {
            throw ValidationException::withMessages([
                'latitude' => ['A valid GPS location is required.'],
            ]);
        }
    }

    private function assertFreshLocationCapture(Carbon $locationCapturedAt): void
    {
        $now = DealerVisit::businessNow();
        $captured = $locationCapturedAt->copy()->timezone('Asia/Kolkata');

        if ($captured->greaterThan($now->copy()->addMinute())) {
            throw ValidationException::withMessages([
                'location_captured_at' => ['Location capture timestamp is invalid. Please capture again.'],
            ]);
        }

        if ($captured->lessThan($now->copy()->subMinutes(2))) {
            throw ValidationException::withMessages([
                'location_captured_at' => ['Location capture is too old. Please capture again.'],
            ]);
        }
    }

    private function formatListItem(DealerVisit $visit): array
    {
        return [
            'id' => $visit->id,
            'dealer_name' => $visit->dealer?->firm_name,
            'visit_date' => $visit->visit_date->toDateString(),
            'visit_time' => $this->formatVisitTime($visit->visit_time),
            'status' => $visit->status,
            'photo_url' => $visit->photoUrl(),
            'latitude' => $visit->latitude,
            'longitude' => $visit->longitude,
        ];
    }

    private function formatDetail(DealerVisit $visit): array
    {
        return [
            'id' => $visit->id,
            'dealer_id' => $visit->dealer_id,
            'dealer_name' => $visit->dealer?->firm_name,
            'owner_name' => $visit->dealer?->owner_name,
            'village' => $visit->dealer?->village,
            'visit_date' => $visit->visit_date->toDateString(),
            'visit_time' => $this->formatVisitTime($visit->visit_time),
            'photo_url' => $visit->photoUrl(),
            'latitude' => $visit->latitude,
            'longitude' => $visit->longitude,
            'accuracy' => $visit->accuracy,
            'location_captured_at' => $visit->location_captured_at
                ?->copy()
                ->timezone('Asia/Kolkata')
                ->toIso8601String(),
            'maps_url' => $visit->mapsUrl(),
            'employee_name' => $visit->employee?->full_name,
            'status' => $visit->status,
            'status_label' => DealerVisit::statusLabel($visit->status),
        ];
    }

    private function formatVisitTime(mixed $visitTime): string
    {
        if ($visitTime instanceof Carbon) {
            return $visitTime->format('H:i');
        }

        return Carbon::parse((string) $visitTime)->format('H:i');
    }
}
