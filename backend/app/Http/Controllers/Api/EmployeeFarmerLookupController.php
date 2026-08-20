<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Farmer;
use App\Models\FieldActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeFarmerLookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile' => ['required', 'string', 'regex:'.Farmer::MOBILE_REGEX],
        ]);

        $farmer = Farmer::query()
            ->with(['district', 'taluka'])
            ->where('mobile', $validated['mobile'])
            ->first();

        if ($farmer === null) {
            return response()->json([
                'found' => false,
                'data' => null,
            ]);
        }

        $employeeId = $request->user()->employee_id;
        $lastActivity = FieldActivity::query()
            ->with(['crop', 'recommendations.product'])
            ->where('farmer_id', $farmer->id)
            ->orderByDesc('activity_date')
            ->orderByDesc('id')
            ->first();

        $summary = null;
        if ($lastActivity !== null) {
            $ownedByEmployee = (int) $lastActivity->employee_id === (int) $employeeId;
            $summary = [
                'activity_date' => $lastActivity->activity_date?->toDateString(),
                'crop_name' => $lastActivity->crop?->name,
                'products' => $lastActivity->recommendations
                    ->map(fn ($row): string => trim(($row->product?->product_name ?? '').($row->dosage ? ' ('.$row->dosage.')' : '')))
                    ->filter()
                    ->values()
                    ->all(),
                'village' => $lastActivity->village,
                'own_activity' => $ownedByEmployee,
            ];
        }

        return response()->json([
            'found' => true,
            'data' => $farmer->toApiArray(),
            'last_activity' => $summary,
        ]);
    }
}
