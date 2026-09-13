<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Services\Dealers\DealerAccessService;
use App\Support\MaharashtraGeography;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeDealerController extends Controller
{
    public function __construct(
        private readonly DealerAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Dealer::query()
            ->where('status', true)
            ->orderBy('firm_name');

        $this->access->scopeVisibleTo($query, $request->user());

        $dealers = $query
            ->get([
                'id',
                'dealer_code',
                'firm_name',
                'owner_name',
                'mobile',
                'email',
                'district',
                'taluka',
                'village',
            ])
            ->map(fn (Dealer $dealer): array => $this->toEmployeeArray($dealer))
            ->values();

        return response()->json(['data' => $dealers]);
    }

    public function show(Request $request, Dealer $dealer): JsonResponse
    {
        $this->assertAssigned($request, $dealer);

        return response()->json(['data' => $this->toEmployeeArray($dealer)]);
    }

    public function update(Request $request, Dealer $dealer): JsonResponse
    {
        $this->assertAssigned($request, $dealer);

        $validated = $request->validate([
            'owner_name' => ['nullable', 'string', 'max:255'],
            'mobile' => [
                'required',
                'regex:/^[6-9][0-9]{9}$/',
                Rule::unique('dealers', 'mobile')->ignore($dealer->id),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'district' => MaharashtraGeography::districtRules(),
            'taluka' => MaharashtraGeography::talukaRules(),
            'village' => ['required', 'string', 'max:255'],
        ], [
            'mobile.regex' => 'Enter a valid 10-digit Indian mobile number.',
            'mobile.unique' => 'A dealer with this mobile number already exists.',
            'email.email' => 'Enter a valid email ID.',
        ], [
            'village' => 'place',
            'email' => 'email ID',
        ]);

        $validated['owner_name'] = filled($validated['owner_name'] ?? null)
            ? trim((string) $validated['owner_name'])
            : null;
        $validated['email'] = filled($validated['email'] ?? null)
            ? trim((string) $validated['email'])
            : null;
        $validated = MaharashtraGeography::canonicalizeLocationFields($validated);

        $dealer->update([
            'owner_name' => $validated['owner_name'],
            'mobile' => $validated['mobile'],
            'email' => $validated['email'],
            'district' => $validated['district'],
            'taluka' => $validated['taluka'],
            'village' => $validated['village'],
        ]);

        return response()->json([
            'message' => 'Dealer details updated.',
            'data' => $this->toEmployeeArray($dealer->fresh() ?? $dealer),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toEmployeeArray(Dealer $dealer): array
    {
        return [
            'id' => $dealer->id,
            'dealer_code' => $dealer->dealer_code,
            'firm_name' => $dealer->firm_name,
            'owner_name' => $dealer->owner_name,
            'mobile' => $dealer->mobile,
            'email' => $dealer->email,
            'district' => $dealer->district,
            'taluka' => $dealer->taluka,
            'village' => $dealer->village,
        ];
    }

    private function assertAssigned(Request $request, Dealer $dealer): void
    {
        $employeeId = $request->user()?->employee_id;

        if (
            $employeeId === null
            || (int) $dealer->assigned_employee_id !== (int) $employeeId
            || ! $dealer->status
        ) {
            abort(403, 'You can only edit dealers assigned to you.');
        }
    }
}
