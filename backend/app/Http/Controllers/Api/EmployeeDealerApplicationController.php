<?php

namespace App\Http\Controllers\Api;

use App\Actions\DealerApplications\DeleteDealerApplicationDocument;
use App\Actions\DealerApplications\SaveDealerApplication;
use App\Actions\DealerApplications\StoreDealerApplicationDocument;
use App\Actions\DealerApplications\SubmitDealerApplication;
use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Models\DealerApplication;
use App\Models\DealerApplicationDocument;
use App\Services\Dealers\DealerApplicationDuplicateChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeDealerApplicationController extends Controller
{
    public function __construct(
        private readonly DealerApplicationDuplicateChecker $duplicates,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DealerApplication::class);

        $employeeId = $request->user()->employee_id;
        if ($employeeId === null) {
            abort(403);
        }

        $validated = $request->validate([
            'tab' => ['nullable', 'string', Rule::in([
                'draft',
                'pending',
                'approved',
                'correction_required',
                'rejected',
            ])],
        ]);

        $tab = $validated['tab'] ?? null;
        $base = DealerApplication::query()->where(function ($query) use ($employeeId): void {
            $query->where('employee_id', $employeeId)
                ->orWhereHas(
                    'dealer',
                    fn ($dealerQuery) => $dealerQuery->where('assigned_employee_id', $employeeId),
                );
        });

        $query = (clone $base)->with(['employee:id,full_name,employee_code', 'dealer:id,dealer_code']);
        $this->applyEmployeeTab($query, $tab);

        $applications = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(20);

        $legacyDealers = [];
        if ($tab === 'approved') {
            $applicationDealerIds = (clone $base)->whereNotNull('dealer_id')->pluck('dealer_id');
            $legacyDealers = Dealer::query()
                ->where('assigned_employee_id', $employeeId)
                ->where('status', true)
                ->whereNotIn('id', $applicationDealerIds->filter()->all() ?: [0])
                ->orderBy('firm_name')
                ->get()
                ->map(fn (Dealer $dealer): array => [
                    'id' => $dealer->id,
                    'item_type' => 'dealer',
                    'firm_name' => $dealer->firm_name,
                    'owner_name' => $dealer->owner_name,
                    'mobile' => $dealer->mobile,
                    'gst_no' => $dealer->gst_no,
                    'state' => $dealer->state,
                    'district' => $dealer->district,
                    'taluka' => $dealer->taluka,
                    'village' => $dealer->village,
                    'location' => collect([$dealer->village, $dealer->taluka, $dealer->district, $dealer->state])
                        ->filter()
                        ->implode(', '),
                    'status' => DealerApplication::STATUS_APPROVED,
                    'status_label' => 'Active',
                    'dealer_id' => $dealer->id,
                    'dealer_code' => $dealer->dealer_code,
                    'latitude' => $dealer->latitude,
                    'longitude' => $dealer->longitude,
                    'can_edit' => false,
                ])
                ->values()
                ->all();
        }

        return response()->json([
            'data' => collect($applications->items())
                ->map(fn (DealerApplication $application): array => $application->toListArray())
                ->values(),
            'legacy_dealers' => $legacyDealers,
            'meta' => [
                'current_page' => $applications->currentPage(),
                'last_page' => $applications->lastPage(),
                'total' => $applications->total(),
            ],
            'counts' => [
                'draft' => (clone $base)->where('status', DealerApplication::STATUS_DRAFT)->count(),
                'pending' => (clone $base)->whereIn('status', [
                    DealerApplication::STATUS_PENDING_MANAGER,
                    DealerApplication::STATUS_PENDING_ADMIN,
                ])->count(),
                'approved' => (clone $base)->where('status', DealerApplication::STATUS_APPROVED)->count(),
                'correction_required' => (clone $base)->where('status', DealerApplication::STATUS_CORRECTION_REQUIRED)->count(),
                'rejected' => (clone $base)->where('status', DealerApplication::STATUS_REJECTED)->count(),
            ],
        ]);
    }

    public function store(Request $request, SaveDealerApplication $save): JsonResponse
    {
        $this->authorize('create', DealerApplication::class);

        $application = $save->execute($request->user(), $this->validatedPayload($request));

        return response()->json([
            'message' => 'Dealer application saved as draft.',
            'data' => $application->toDetailArray(),
            'duplicate_warning' => (bool) $application->duplicate_warning,
            'duplicate_matches' => $this->duplicates->matches($application),
        ], 201);
    }

    public function show(Request $request, DealerApplication $dealerApplication): JsonResponse
    {
        $this->authorize('view', $dealerApplication);
        $dealerApplication->load(['employee:id,full_name,employee_code', 'documents.uploadedByUser', 'events', 'dealer', 'party']);

        return response()->json(['data' => $dealerApplication->toDetailArray()]);
    }

    public function update(Request $request, DealerApplication $dealerApplication, SaveDealerApplication $save): JsonResponse
    {
        $this->authorize('update', $dealerApplication);

        $application = $save->execute($request->user(), $this->validatedPayload($request), $dealerApplication);

        return response()->json([
            'message' => 'Dealer application updated.',
            'data' => $application->toDetailArray(),
            'duplicate_warning' => (bool) $application->duplicate_warning,
            'duplicate_matches' => $this->duplicates->matches($application),
        ]);
    }

    public function submit(Request $request, DealerApplication $dealerApplication, SubmitDealerApplication $submit): JsonResponse
    {
        $this->authorize('submit', $dealerApplication);

        $application = $submit->execute($dealerApplication, $request->user());
        $matches = $this->duplicates->matches($application);

        return response()->json([
            'message' => 'Dealer application submitted for manager approval.',
            'data' => $application->toDetailArray(),
            'duplicate_warning' => $matches !== [],
            'duplicate_matches' => $matches,
        ]);
    }

    public function uploadDocument(
        Request $request,
        DealerApplication $dealerApplication,
        StoreDealerApplicationDocument $store,
    ): JsonResponse {
        $this->authorize('uploadDocument', $dealerApplication);

        $validated = $request->validate([
            'document_type' => ['required', 'string', Rule::in(array_keys(DealerApplicationDocument::TYPE_LABELS))],
            'file' => [
                'required',
                'file',
                'max:'.DealerApplicationDocument::MAX_SIZE_KB,
                'mimes:pdf,jpg,jpeg,png',
            ],
        ]);

        $document = $store->execute(
            $dealerApplication,
            $request->user(),
            $validated['document_type'],
            $request->file('file'),
        );

        $dealerApplication->load(['employee:id,full_name,employee_code', 'documents.uploadedByUser', 'events', 'dealer']);

        return response()->json([
            'message' => $document->typeLabel().' uploaded.',
            'data' => $document->toApiArray(),
            'application' => $dealerApplication->toDetailArray(),
        ]);
    }

    public function deleteDocument(
        Request $request,
        DealerApplication $dealerApplication,
        DealerApplicationDocument $dealerApplicationDocument,
        DeleteDealerApplicationDocument $delete,
    ): JsonResponse {
        $this->authorize('uploadDocument', $dealerApplication);

        $application = $delete->execute(
            $dealerApplication,
            $dealerApplicationDocument,
            $request->user(),
        );

        return response()->json([
            'message' => 'Document removed.',
            'data' => $application->toDetailArray(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'firm_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'regex:'.DealerApplication::MOBILE_REGEX],
            'gst_no' => ['nullable', 'string', 'max:15', 'regex:'.DealerApplication::GST_REGEX],
            'state' => ['required', 'string', 'max:255'],
            'district' => ['required', 'string', 'max:255'],
            'taluka' => ['required', 'string', 'max:255'],
            'village' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<DealerApplication>  $query
     */
    private function applyEmployeeTab($query, ?string $tab): void
    {
        match ($tab) {
            'draft' => $query->where('status', DealerApplication::STATUS_DRAFT),
            'pending' => $query->whereIn('status', [
                DealerApplication::STATUS_PENDING_MANAGER,
                DealerApplication::STATUS_PENDING_ADMIN,
            ]),
            'approved' => $query->where('status', DealerApplication::STATUS_APPROVED),
            'correction_required' => $query->where('status', DealerApplication::STATUS_CORRECTION_REQUIRED),
            'rejected' => $query->where('status', DealerApplication::STATUS_REJECTED),
            default => null,
        };
    }
}
