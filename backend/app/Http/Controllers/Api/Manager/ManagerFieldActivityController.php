<?php

namespace App\Http\Controllers\Api\Manager;

use App\Http\Controllers\Api\EmployeeFieldActivityController;
use App\Http\Controllers\Controller;
use App\Models\FieldActivity;
use App\Services\Orders\ManagerOrderAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagerFieldActivityController extends Controller
{
    public function __construct(
        private readonly ManagerOrderAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer'],
            'district_id' => ['nullable', 'integer', 'exists:maharashtra_districts,id'],
            'taluka_id' => ['nullable', 'integer', 'exists:maharashtra_talukas,id'],
            'crop_id' => ['nullable', 'integer', 'exists:crops,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $reportIds = $this->access->directReportEmployeeIds($request->user());
        $query = FieldActivity::query()
            ->with(['employee:id,full_name,employee_code', 'crop', 'recommendations.product'])
            ->whereIn('employee_id', $reportIds === [] ? [0] : $reportIds);

        $this->applyFilters($query, $validated);

        $activities = $query
            ->orderByDesc('activity_date')
            ->orderByDesc('id')
            ->paginate(20);

        $formatter = app(EmployeeFieldActivityController::class);

        return response()->json([
            'data' => collect($activities->items())
                ->map(fn (FieldActivity $activity): array => $formatter->formatDetail($activity))
                ->values(),
            'meta' => [
                'current_page' => $activities->currentPage(),
                'last_page' => $activities->lastPage(),
                'total' => $activities->total(),
            ],
        ]);
    }

    public function show(Request $request, FieldActivity $fieldActivity): JsonResponse
    {
        $reportIds = $this->access->directReportEmployeeIds($request->user());
        if (! in_array((int) $fieldActivity->employee_id, $reportIds, true)) {
            abort(403, 'You can only view field activities of employees reporting to you.');
        }

        $fieldActivity->load([
            'employee:id,full_name,employee_code',
            'crop',
            'farmer.district',
            'farmer.taluka',
            'recommendations.product',
            'recommendations.crop',
        ]);

        return response()->json([
            'data' => app(EmployeeFieldActivityController::class)->formatDetail($fieldActivity),
        ]);
    }

    /**
     * @param  Builder<FieldActivity>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (filled($filters['employee_id'] ?? null)) {
            $query->where('employee_id', $filters['employee_id']);
        }
        if (filled($filters['district_id'] ?? null)) {
            $query->where('district_id', $filters['district_id']);
        }
        if (filled($filters['taluka_id'] ?? null)) {
            $query->where('taluka_id', $filters['taluka_id']);
        }
        if (filled($filters['crop_id'] ?? null)) {
            $query->where('crop_id', $filters['crop_id']);
        }
        if (filled($filters['product_id'] ?? null)) {
            $query->whereHas(
                'recommendations',
                fn (Builder $inner) => $inner->where('product_id', $filters['product_id']),
            );
        }
        if (filled($filters['date_from'] ?? null)) {
            $query->whereDate('activity_date', '>=', $filters['date_from']);
        }
        if (filled($filters['date_to'] ?? null)) {
            $query->whereDate('activity_date', '<=', $filters['date_to']);
        }
    }
}
