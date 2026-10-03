<?php

namespace App\Services\TaDa;

use App\Models\Employee;
use App\Models\TaDaClaim;
use App\Models\TaDaSetting;
use App\Services\TaDaClaimRouteService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class TaDaClaimSubmissionService
{
    public function __construct(
        private readonly TaDaClaimRouteService $routeService,
    ) {}

    public function submit(Employee $employee, Request $request, string $submitterRole): TaDaClaim
    {
        if (! $request->isMethod('POST')) {
            abort(405, 'TA/DA claims can only be created via POST submit.');
        }

        $validated = $request->validate([
            'claim_date' => ['required', 'date'],
            'from_location' => ['required', 'string', 'max:255'],
            'to_location' => ['required', 'string', 'max:255'],
            'da_amount' => ['nullable', 'numeric', 'min:0'],
            'other_expense' => ['nullable', 'numeric', 'min:0'],
            'employee_remarks' => ['nullable', 'string', 'max:2000'],
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $claimDate = $this->routeService->claimDateString($validated['claim_date']);
        $this->assertClaimDateAvailable((int) $employee->id, $claimDate);

        $perKmRate = TaDaSetting::resolvePerKmRate($employee);
        $routeData = $this->routeService->resolveTravelKm($employee, $claimDate);
        $travelKm = $routeData['travel_km'];
        $daAmount = round((float) ($validated['da_amount'] ?? 0), 2);
        $otherExpense = round((float) ($validated['other_expense'] ?? 0), 2);
        $travelAmount = round($travelKm * $perKmRate, 2);
        $totalAmount = round($travelAmount + $daAmount + $otherExpense, 2);

        $photoPath = str_replace('\\', '/', $request->file('photo')->store('ta-da-claims', 'public'));

        $claim = TaDaClaim::query()->create([
            'employee_id' => $employee->id,
            'submitter_role' => $submitterRole,
            'claim_date' => $claimDate,
            'from_location' => trim($validated['from_location']),
            'to_location' => trim($validated['to_location']),
            'travel_km' => $travelKm,
            'per_km_rate' => $perKmRate,
            'travel_amount' => $travelAmount,
            'da_amount' => $daAmount,
            'other_expense' => $otherExpense,
            'total_amount' => $totalAmount,
            'bill_photo_path' => $photoPath,
            'employee_remarks' => filled($validated['employee_remarks'] ?? null)
                ? trim($validated['employee_remarks'])
                : null,
            'status' => TaDaClaim::STATUS_PENDING,
        ]);

        return $claim->load('employee:id,full_name');
    }

    /**
     * @return array<string, mixed>
     */
    public function travelSummary(Employee $employee, string $claimDateInput): array
    {
        $claimDate = $this->routeService->claimDateString($claimDateInput);
        $this->assertClaimDateAvailable((int) $employee->id, $claimDate);

        $perKmRate = TaDaSetting::resolvePerKmRate($employee);
        $routeData = $this->routeService->resolveTravelKm($employee, $claimDate);
        $travelKm = $routeData['travel_km'];

        return [
            'claim_date' => $claimDate,
            'travel_km' => $travelKm,
            'per_km_rate' => $perKmRate,
            'travel_amount' => round($travelKm * $perKmRate, 2),
            'route_available' => true,
            'attendance_id' => $routeData['attendance_id'],
            'valid_point_count' => $routeData['valid_point_count'],
        ];
    }

    public function assertClaimDateAvailable(int $employeeId, string $claimDate): void
    {
        $exists = TaDaClaim::query()
            ->where('employee_id', $employeeId)
            ->whereDate('claim_date', $claimDate)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'claim_date' => ['A TA/DA claim already exists for this date.'],
            ]);
        }
    }
}
