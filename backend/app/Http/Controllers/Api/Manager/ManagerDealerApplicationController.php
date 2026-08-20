<?php

namespace App\Http\Controllers\Api\Manager;

use App\Actions\DealerApplications\ReviewDealerApplication;
use App\Http\Controllers\Controller;
use App\Models\DealerApplication;
use App\Services\Orders\ManagerOrderAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ManagerDealerApplicationController extends Controller
{
    public function __construct(
        private readonly ManagerOrderAccessService $team,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DealerApplication::class);

        $validated = $request->validate([
            'tab' => ['nullable', 'string', Rule::in([
                'pending',
                'approved',
                'correction_required',
                'rejected',
            ])],
        ]);

        $reportIds = $this->team->directReportEmployeeIds($request->user());
        $base = DealerApplication::query()->whereIn('employee_id', $reportIds === [] ? [0] : $reportIds);

        $query = (clone $base)->with(['employee:id,full_name,employee_code', 'dealer:id,dealer_code']);
        match ($validated['tab'] ?? 'pending') {
            'pending' => $query->where('status', DealerApplication::STATUS_PENDING_MANAGER),
            'approved' => $query->whereIn('status', [
                DealerApplication::STATUS_PENDING_ADMIN,
                DealerApplication::STATUS_APPROVED,
            ]),
            'correction_required' => $query->where('status', DealerApplication::STATUS_CORRECTION_REQUIRED),
            'rejected' => $query->where('status', DealerApplication::STATUS_REJECTED),
            default => $query->where('status', DealerApplication::STATUS_PENDING_MANAGER),
        };

        $applications = $query
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($applications->items())
                ->map(fn (DealerApplication $application): array => $application->toListArray())
                ->values(),
            'meta' => [
                'current_page' => $applications->currentPage(),
                'last_page' => $applications->lastPage(),
                'total' => $applications->total(),
            ],
            'counts' => [
                'pending' => (clone $base)->where('status', DealerApplication::STATUS_PENDING_MANAGER)->count(),
                'approved' => (clone $base)->whereIn('status', [
                    DealerApplication::STATUS_PENDING_ADMIN,
                    DealerApplication::STATUS_APPROVED,
                ])->count(),
                'correction_required' => (clone $base)->where('status', DealerApplication::STATUS_CORRECTION_REQUIRED)->count(),
                'rejected' => (clone $base)->where('status', DealerApplication::STATUS_REJECTED)->count(),
            ],
        ]);
    }

    public function show(DealerApplication $dealerApplication): JsonResponse
    {
        $this->authorize('view', $dealerApplication);
        $dealerApplication->load(['employee:id,full_name,employee_code', 'documents.uploadedByUser', 'events', 'dealer', 'party']);

        return response()->json(['data' => $dealerApplication->toDetailArray()]);
    }

    public function approve(Request $request, DealerApplication $dealerApplication, ReviewDealerApplication $review): JsonResponse
    {
        $this->authorize('approveAsManager', $dealerApplication);

        $validated = $request->validate([
            'remark' => ['nullable', 'string', 'max:2000'],
        ]);

        $application = $review->execute(
            $dealerApplication,
            $request->user(),
            ReviewDealerApplication::ACTION_APPROVE,
            'manager',
            $validated['remark'] ?? null,
        );

        return response()->json([
            'message' => 'Dealer application approved. Waiting for Admin final approval.',
            'data' => $application->toDetailArray(),
        ]);
    }

    public function reject(Request $request, DealerApplication $dealerApplication, ReviewDealerApplication $review): JsonResponse
    {
        $this->authorize('rejectAsManager', $dealerApplication);

        $validated = $request->validate([
            'remark' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $application = $review->execute(
            $dealerApplication,
            $request->user(),
            ReviewDealerApplication::ACTION_REJECT,
            'manager',
            $validated['remark'],
        );

        return response()->json([
            'message' => 'Dealer application rejected.',
            'data' => $application->toDetailArray(),
        ]);
    }

    public function sendBack(Request $request, DealerApplication $dealerApplication, ReviewDealerApplication $review): JsonResponse
    {
        $this->authorize('sendBackAsManager', $dealerApplication);

        $validated = $request->validate([
            'remark' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $application = $review->execute(
            $dealerApplication,
            $request->user(),
            ReviewDealerApplication::ACTION_SEND_BACK,
            'manager',
            $validated['remark'],
        );

        return response()->json([
            'message' => 'Dealer application sent back for correction.',
            'data' => $application->toDetailArray(),
        ]);
    }
}
