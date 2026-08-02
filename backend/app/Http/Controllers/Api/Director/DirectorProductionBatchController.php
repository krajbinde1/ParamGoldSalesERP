<?php

namespace App\Http\Controllers\Api\Director;

use App\Enums\ProductionBatchStatus;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Resources\Production\ProductionBatchPresenter;
use App\Models\ProductionBatch;
use App\Services\Inventory\ProductionWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DirectorProductionBatchController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly ProductionWorkflowService $workflow,
    ) {}

    public function pendingApprovals(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ProductionBatch::class);

        $batches = ProductionBatch::query()
            ->with(['product', 'bom', 'supervisor'])
            ->where('status', ProductionBatchStatus::DeviationPendingApproval)
            ->orderByDesc('submitted_for_approval_at')
            ->paginate(20);

        return $this->paginated(
            'Pending deviation approvals loaded successfully.',
            $batches,
            fn (ProductionBatch $batch): array => ProductionBatchPresenter::detail($batch, $request->user()),
        );
    }

    public function approveDeviation(Request $request, ProductionBatch $batch): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $batch = $this->workflow->approveDeviation($batch, $request->user(), $validated['notes'] ?? null);

        return $this->ok('Production batch deviation approved successfully.', ProductionBatchPresenter::detail($batch, $request->user()));
    }

    public function rejectDeviation(Request $request, ProductionBatch $batch): JsonResponse
    {
        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);

        $batch = $this->workflow->rejectDeviation($batch, $request->user(), $validated['rejection_reason']);

        return $this->ok('Production batch deviation rejected successfully.', ProductionBatchPresenter::detail($batch, $request->user()));
    }
}
