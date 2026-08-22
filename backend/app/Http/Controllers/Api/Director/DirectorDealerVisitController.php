<?php

namespace App\Http\Controllers\Api\Director;

use App\Http\Controllers\Controller;
use App\Models\DealerVisit;
use App\Support\AttendanceCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Company-wide Director dealer visit monitoring (view-only).
 */
class DirectorDealerVisitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
        ]);

        $date = $validated['date'] ?? AttendanceCalendar::today()->toDateString();

        $visits = DealerVisit::query()
            ->with([
                'dealer:id,dealer_code,firm_name,owner_name,village,taluka,district,address',
                'employee:id,full_name,employee_code',
            ])
            ->whereDate('visit_date', $date)
            ->orderByDesc('visit_time')
            ->orderByDesc('id')
            ->get()
            ->map(fn (DealerVisit $visit): array => $this->formatListItem($visit))
            ->values();

        return response()->json([
            'data' => $visits,
            'meta' => [
                'date' => $date,
                'total' => $visits->count(),
            ],
        ]);
    }

    public function show(DealerVisit $dealerVisit): JsonResponse
    {
        $dealerVisit->load(['dealer', 'employee']);

        return response()->json([
            'data' => $this->formatDetail($dealerVisit),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatListItem(DealerVisit $visit): array
    {
        $dealer = $visit->dealer;
        $locationParts = array_filter([
            $dealer?->village,
            $dealer?->taluka,
            $dealer?->district,
        ]);

        return [
            'id' => $visit->id,
            'dealer_id' => $visit->dealer_id,
            'dealer_name' => $dealer?->firm_name,
            'dealer_code' => $dealer?->dealer_code,
            'owner_name' => $dealer?->owner_name,
            'village' => $dealer?->village,
            'taluka' => $dealer?->taluka,
            'district' => $dealer?->district,
            'location' => $locationParts !== [] ? implode(', ', $locationParts) : ($dealer?->address),
            'employee_id' => $visit->employee_id,
            'employee_name' => $visit->employee?->full_name,
            'employee_code' => $visit->employee?->employee_code,
            'visit_date' => $visit->visit_date?->toDateString(),
            'visit_time' => $this->formatVisitTime($visit->visit_time),
            'status' => $visit->status,
            'status_label' => DealerVisit::statusLabel($visit->status),
            'remark' => null,
            'latitude' => $visit->latitude !== null ? (float) $visit->latitude : null,
            'longitude' => $visit->longitude !== null ? (float) $visit->longitude : null,
            'maps_url' => $visit->mapsUrl(),
            'photo_url' => $visit->photoUrl(),
            'location_available' => $visit->latitude !== null && $visit->longitude !== null,
        ];
    }

    /**
     * Shape matches employee dealer-visit detail so Flutter can reuse DealerVisitDetail.
     *
     * @return array<string, mixed>
     */
    private function formatDetail(DealerVisit $visit): array
    {
        $dealer = $visit->dealer;

        return [
            'id' => $visit->id,
            'dealer_id' => $visit->dealer_id,
            'dealer_name' => $dealer?->firm_name,
            'dealer_code' => $dealer?->dealer_code,
            'owner_name' => $dealer?->owner_name,
            'village' => $dealer?->village,
            'taluka' => $dealer?->taluka,
            'district' => $dealer?->district,
            'visit_date' => $visit->visit_date?->toDateString(),
            'visit_time' => $this->formatVisitTime($visit->visit_time),
            'photo_url' => $visit->photoUrl(),
            'latitude' => $visit->latitude !== null ? (float) $visit->latitude : 0,
            'longitude' => $visit->longitude !== null ? (float) $visit->longitude : 0,
            'accuracy' => $visit->accuracy !== null ? (float) $visit->accuracy : 0,
            'location_captured_at' => $visit->location_captured_at
                ?->copy()
                ->timezone(AttendanceCalendar::TIMEZONE)
                ->toIso8601String(),
            'maps_url' => $visit->mapsUrl(),
            'employee_name' => $visit->employee?->full_name,
            'employee_code' => $visit->employee?->employee_code,
            'status' => $visit->status,
            'status_label' => DealerVisit::statusLabel($visit->status),
            'remark' => null,
        ];
    }

    private function formatVisitTime(mixed $visitTime): string
    {
        if ($visitTime instanceof Carbon) {
            return $visitTime->format('H:i');
        }

        if (! filled($visitTime)) {
            return '';
        }

        return Carbon::parse((string) $visitTime)->format('H:i');
    }
}
