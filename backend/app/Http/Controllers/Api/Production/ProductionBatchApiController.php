<?php

namespace App\Http\Controllers\Api\Production;

use App\Enums\ProductionBatchStatus;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Resources\Production\ProductionBatchPresenter;
use App\Models\Bom;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\User;
use App\Services\Inventory\ProductionService;
use App\Services\Inventory\ProductionWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductionBatchApiController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly ProductionWorkflowService $workflow,
        private readonly ProductionService $productionService,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        $this->authorize('create', ProductionBatch::class);

        $input = $this->validatePayload($request);
        $user = $request->user();

        $preview = $this->productionService->preview($input);

        return $this->ok('Production preview generated successfully.', $this->presentPreview($preview, $user));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ProductionBatch::class);

        $input = $this->validatePayload($request);
        $user = $request->user();

        $batch = $this->workflow->saveDraft($input, $user);

        return $this->ok('Production batch draft saved successfully.', ProductionBatchPresenter::detail($batch, $user), 201);
    }

    /**
     * Direct-create + post production (matches Filament Create Production Entry).
     * Wraps ProductionService::completeProduction — no Flutter-side stock math.
     */
    public function confirm(Request $request): JsonResponse
    {
        $this->authorize('create', ProductionBatch::class);

        $input = $this->validatePayload($request);
        $user = $request->user();

        if (! $user->canPostProduction()) {
            abort(403, 'You are not authorized to post production.');
        }

        $batch = $this->productionService->completeProduction($input, $user);

        return $this->ok(
            'Production completed and stock posted successfully.',
            ProductionBatchPresenter::detail($batch->fresh(['product', 'bom', 'supervisor', 'consumptions']), $user),
            201,
        );
    }

    public function update(Request $request, ProductionBatch $batch): JsonResponse
    {
        $this->assertCanManageBatch($batch, $request->user());

        $input = $this->validatePayload($request);
        $user = $request->user();

        $batch = $this->workflow->saveDraft($input, $user, $batch);

        return $this->ok('Production batch draft updated successfully.', ProductionBatchPresenter::detail($batch, $user));
    }

    public function submitApproval(Request $request, ProductionBatch $batch): JsonResponse
    {
        $this->assertCanManageBatch($batch, $request->user());

        $batch = $this->workflow->submitForApproval($batch, $request->user());

        return $this->ok('Production batch submitted for deviation approval.', ProductionBatchPresenter::detail($batch, $request->user()));
    }

    public function start(Request $request, ProductionBatch $batch): JsonResponse
    {
        $this->assertCanManageBatch($batch, $request->user());

        $batch = $this->workflow->startProduction($batch, $request->user());

        return $this->ok('Production batch started successfully.', ProductionBatchPresenter::detail($batch, $request->user()));
    }

    public function complete(Request $request, ProductionBatch $batch): JsonResponse
    {
        $this->assertCanManageBatch($batch, $request->user());

        $validated = $request->validate([
            'posting_token' => ['nullable', 'string', 'max:191'],
        ]);

        $batch = $this->workflow->complete($batch, $request->user(), $validated['posting_token'] ?? null);

        return $this->ok('Production batch completed and stock posted successfully.', ProductionBatchPresenter::detail($batch, $request->user()));
    }

    public function cancel(Request $request, ProductionBatch $batch): JsonResponse
    {
        $this->assertCanManageBatch($batch, $request->user());

        $batch = $this->workflow->cancelDraft($batch, $request->user());

        return $this->ok('Production batch cancelled successfully.', ProductionBatchPresenter::detail($batch, $request->user()));
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ProductionBatch::class);

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(ProductionBatchStatus::cases(), 'value'))],
        ]);

        $user = $request->user();

        $batches = ProductionBatch::query()
            ->with(['product', 'semiFinished', 'supervisor'])
            ->when(! $user->usesAdminDirectorDashboard() && ! $user->isAdminUser(), fn ($query) => $query->where('supervisor_id', $user->id))
            ->when(filled($validated['status'] ?? null), fn ($query) => $query->where('status', $validated['status']))
            ->orderByDesc('id')
            ->paginate(20);

        return $this->paginated(
            'Production batches loaded successfully.',
            $batches,
            fn (ProductionBatch $batch): array => ProductionBatchPresenter::summary($batch, $user),
        );
    }

    public function show(Request $request, ProductionBatch $batch): JsonResponse
    {
        $this->authorize('view', $batch);

        $batch->load(['product', 'semiFinished', 'bom', 'supervisor', 'approvedBy', 'rejectedBy', 'consumptions']);

        return $this->ok('Production batch details loaded successfully.', ProductionBatchPresenter::detail($batch, $request->user()));
    }

    public function history(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ProductionBatch::class);

        $validated = $request->validate([
            'output_type' => ['nullable', 'in:finished_product,semi_finished'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'semi_finished_id' => ['nullable', 'integer', 'exists:semi_finished_materials,id'],
            'batch_number' => ['nullable', 'string', 'max:191'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(ProductionBatchStatus::cases(), 'value'))],
        ]);

        $user = $request->user();
        $status = $validated['status'] ?? ProductionBatchStatus::Completed->value;

        $batches = ProductionBatch::query()
            ->with(['product', 'semiFinished', 'supervisor'])
            ->where('status', $status)
            ->when(! $user->usesAdminDirectorDashboard() && ! $user->isAdminUser(), fn ($query) => $query->where('supervisor_id', $user->id))
            ->when(filled($validated['output_type'] ?? null), fn ($query) => $query->where('output_type', $validated['output_type']))
            ->when(filled($validated['product_id'] ?? null), fn ($query) => $query->where('product_id', $validated['product_id']))
            ->when(filled($validated['semi_finished_id'] ?? null), fn ($query) => $query->where('semi_finished_id', $validated['semi_finished_id']))
            ->when(filled($validated['batch_number'] ?? null), function ($query) use ($validated) {
                $term = $validated['batch_number'];
                $query->where(function ($inner) use ($term) {
                    $inner->where('batch_number', 'like', "%{$term}%")
                        ->orWhereHas('product', fn ($pq) => $pq->where('product_name', 'like', "%{$term}%")
                            ->orWhere('product_code', 'like', "%{$term}%"))
                        ->orWhereHas('semiFinished', fn ($sq) => $sq->where('material_name', 'like', "%{$term}%")
                            ->orWhere('material_code', 'like', "%{$term}%"));
                });
            })
            ->when(filled($validated['from'] ?? null), fn ($query) => $query->whereDate('production_date', '>=', $validated['from']))
            ->when(filled($validated['to'] ?? null), fn ($query) => $query->whereDate('production_date', '<=', $validated['to']))
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->paginate(20);

        return $this->paginated(
            'Production batch history loaded successfully.',
            $batches,
            fn (ProductionBatch $batch): array => ProductionBatchPresenter::summary($batch, $user),
        );
    }

    /**
     * Batch status transitions (draft edit, submit, start, complete, cancel) are
     * gated by the supervisor owning the batch, or a director/admin acting on
     * behalf of the team. Fine-grained status rules are enforced by the
     * ProductionWorkflowService itself and surfaced as ValidationException (422).
     */
    private function assertCanManageBatch(ProductionBatch $batch, User $user): void
    {
        if (! $user->canPostProduction()) {
            abort(403, 'You are not authorized to manage production batches.');
        }

        $isOwner = (int) $batch->supervisor_id === (int) $user->id;
        $isDirectorOrAdmin = $user->usesAdminDirectorDashboard() || $user->isAdminUser();

        if (! $isOwner && ! $isDirectorOrAdmin) {
            abort(403, 'You are not authorized to manage this production batch.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'output_type' => ['nullable', 'in:finished_product,semi_finished'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'semi_finished_id' => ['nullable', 'integer', 'exists:semi_finished_materials,id'],
            'production_date' => ['required', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'production_quantity' => ['nullable', 'numeric', 'min:0.001'],
            'planned_quantity' => ['nullable', 'numeric', 'min:0.001'],
            'actual_output_quantity' => ['nullable', 'numeric', 'min:0.001'],
            'wastage_quantity' => ['nullable', 'numeric', 'min:0'],
            'batch_number' => ['nullable', 'string', 'max:191'],
            'labour_cost' => ['nullable', 'numeric', 'min:0'],
            'transport_cost' => ['nullable', 'numeric', 'min:0'],
            'other_manufacturing_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'posting_token' => ['nullable', 'string', 'max:191'],
            'materials' => ['nullable', 'array'],
            'materials.*.bom_item_id' => ['required_with:materials', 'integer'],
            'materials.*.is_substituted' => ['nullable', 'boolean'],
            'materials.*.raw_material_id' => ['nullable', 'integer', 'exists:raw_materials,id'],
            'materials.*.packaging_material_id' => ['nullable', 'integer', 'exists:packaging_materials,id'],
            'materials.*.semi_finished_id' => ['nullable', 'integer', 'exists:semi_finished_materials,id'],
            'materials.*.conversion_ratio' => ['nullable', 'numeric', 'min:0.000001'],
            'materials.*.consumed_quantity' => ['nullable', 'numeric', 'min:0'],
            'materials.*.substitution_reason' => ['nullable', 'string', 'max:100'],
            'materials.*.substitution_remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $outputType = $validated['output_type'] ?? 'finished_product';
        if ($outputType === 'semi_finished' && empty($validated['semi_finished_id'])) {
            throw ValidationException::withMessages([
                'semi_finished_id' => 'Select a semi-finished material to produce.',
            ]);
        }
        if ($outputType === 'finished_product' && empty($validated['product_id'])) {
            throw ValidationException::withMessages([
                'product_id' => 'Select a finished product to produce.',
            ]);
        }

        $validated['output_type'] = $outputType;

        $planned = (float) ($validated['planned_quantity']
            ?? $validated['production_quantity']
            ?? 0);
        if ($planned <= 0) {
            throw ValidationException::withMessages([
                'production_quantity' => 'Enter a valid production quantity.',
            ]);
        }

        $actual = (float) ($validated['actual_output_quantity'] ?? $planned);
        $wastage = max(0, (float) ($validated['wastage_quantity'] ?? 0));

        $validated['planned_quantity'] = $planned;
        $validated['actual_output_quantity'] = $actual;
        $validated['wastage_quantity'] = $wastage;
        $validated['manufacturing_date'] = $validated['production_date'];
        $validated['electricity_cost'] = 0;
        $validated['machine_cost'] = 0;
        $validated['processing_cost'] = 0;
        unset($validated['production_quantity']);

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function presentPreview(array $preview, User $user): array
    {
        $showCosts = $user->canViewProductionCosts();
        /** @var ?Product $product */
        $product = $preview['product'] ?? null;
        /** @var ?\App\Models\SemiFinishedMaterial $semiFinished */
        $semiFinished = $preview['semi_finished'] ?? null;
        /** @var Bom $bom */
        $bom = $preview['bom'];
        $outputType = (string) ($preview['output_type'] ?? 'finished_product');

        return [
            'output_type' => $outputType,
            'product' => $product ? [
                'id' => $product->id,
                'product_code' => $product->product_code,
                'product_name' => $product->product_name,
                'label' => $product->displayLabel(),
            ] : null,
            'semi_finished' => $semiFinished ? [
                'id' => $semiFinished->id,
                'material_code' => $semiFinished->material_code,
                'material_name' => $semiFinished->material_name,
                'label' => trim($semiFinished->material_code.' — '.$semiFinished->material_name),
                'unit' => $semiFinished->unit,
            ] : null,
            'bom' => [
                'id' => $bom->id,
                'bom_number' => $bom->bom_number,
                'bom_version' => $bom->bom_version,
                'batch_quantity' => (float) $bom->batch_quantity,
                'batch_unit' => (string) $bom->batch_unit,
                'formula_quantity_label' => $bom->formulaQuantityLabel(),
                'output_quantity' => (float) $bom->batch_quantity,
            ],
            'requirements' => array_map(
                fn (array $line): array => $this->presentRequirementLine($line, $showCosts),
                $preview['requirements'],
            ),
            'costing' => $showCosts ? $preview['costing'] : null,
            'has_material_deviation' => false,
            'has_quantity_variance' => false,
            'requires_approval' => false,
            'has_mandatory_shortage' => (bool) ($preview['has_mandatory_shortage'] ?? false),
            'can_view_costs' => $showCosts,
            'substitution_reasons' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function presentRequirementLine(array $line, bool $showCosts): array
    {
        $stockStatus = (string) ($line['stock_status'] ?? '');
        if ($stockStatus === '' && ((float) ($line['shortage_quantity'] ?? 0)) > 0) {
            $stockStatus = 'insufficient';
        } elseif ($stockStatus === '' || $stockStatus === 'available') {
            $available = (float) ($line['available_stock'] ?? 0);
            $required = (float) ($line['required_quantity'] ?? 0);
            $min = (float) ($line['minimum_stock'] ?? 0);
            if ($available < $required) {
                $stockStatus = 'insufficient';
            } elseif ($min > 0 && ($available - $required) <= $min) {
                $stockStatus = 'low';
            } else {
                $stockStatus = 'sufficient';
            }
        }

        $line['stock_status'] = $stockStatus;
        $line['stock_status_label'] = match ($stockStatus) {
            'insufficient', 'out', 'shortage' => 'Insufficient',
            'low', 'low_stock' => 'Low',
            default => 'Sufficient',
        };

        $line['alternates'] = array_map(function (array $alternate) use ($showCosts): array {
            if (! $showCosts) {
                $alternate['average_rate'] = null;
            }

            return $alternate;
        }, $line['alternates'] ?? []);

        if (! $showCosts) {
            $line['average_rate'] = null;
            $line['estimated_value'] = null;
        }

        return $line;
    }
}
