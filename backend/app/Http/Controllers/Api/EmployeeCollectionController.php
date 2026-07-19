<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Dealer;
use App\Services\Dealers\DealerAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class EmployeeCollectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        $today = Collection::businessToday();
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $weekEnd = $today->copy()->endOfWeek(Carbon::MONDAY)->toDateString();
        $monthStart = $today->copy()->startOfMonth()->toDateString();
        $monthEnd = $today->copy()->endOfMonth()->toDateString();

        $collections = Collection::query()->where('sales_employee_id', $employee->id);

        $received = (clone $collections)->where('status', Collection::STATUS_RECEIVED);

        $summary = [
            'total_collection' => round((float) (clone $received)->sum('amount'), 2),
            'month_collection' => round((float) (clone $received)
                ->whereBetween('collection_date', [$monthStart, $monthEnd])
                ->sum('amount'), 2),
            'week_collection' => round((float) (clone $received)
                ->whereBetween('collection_date', [$weekStart, $weekEnd])
                ->sum('amount'), 2),
            'total_entries' => (clone $collections)->count(),
        ];

        $recentCollections = (clone $collections)
            ->with('dealer:id,firm_name')
            ->orderByDesc('collection_date')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (Collection $collection): array => $this->formatListItem($collection))
            ->values();

        return response()->json([
            'summary' => $summary,
            'recent_collections' => $recentCollections,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        $validated = $request->validate([
            'dealer_id' => ['required', 'integer', 'exists:dealers,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'collection_date' => ['required', 'date'],
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $dealer = app(DealerAccessService::class)->resolveAccessibleActiveDealer(
            $request->user(),
            (int) $validated['dealer_id'],
        );

        if ($dealer === null) {
            throw ValidationException::withMessages([
                'dealer_id' => 'Selected dealer is not available.',
            ]);
        }

        $collectionDate = Carbon::parse($validated['collection_date'], Collection::businessToday()->timezoneName)
            ->startOfDay();

        if ($collectionDate->greaterThan(Collection::businessToday())) {
            throw ValidationException::withMessages([
                'collection_date' => 'Collection date cannot be in the future.',
            ]);
        }

        $photoPath = str_replace('\\', '/', $request->file('photo')->store('collections', 'public'));

        $collection = Collection::query()->create([
            'sales_employee_id' => $employee->id,
            'dealer_id' => $dealer->id,
            'amount' => $validated['amount'],
            'collection_date' => $collectionDate->toDateString(),
            'photo_path' => $photoPath,
            'remarks' => $validated['remarks'] ?? null,
            'status' => Collection::STATUS_PENDING,
        ]);

        $collection->load('dealer:id,firm_name');

        return response()->json([
            'message' => 'Collection submitted successfully.',
            'data' => $this->formatDetail($collection),
        ], 201);
    }

    public function show(Request $request, Collection $collection): JsonResponse
    {
        $this->authorizeEmployeeCollection($request, $collection);

        $collection->load('dealer:id,firm_name,owner_name,village,mobile');

        return response()->json([
            'data' => $this->formatDetail($collection),
        ]);
    }

    private function authorizeEmployeeCollection(Request $request, Collection $collection): void
    {
        if ($collection->sales_employee_id !== $request->user()->employee->id) {
            abort(403, 'You are not allowed to access this collection.');
        }
    }

    private function formatListItem(Collection $collection): array
    {
        return [
            'id' => $collection->id,
            'dealer_name' => $collection->dealer?->firm_name ?? '-',
            'amount' => (float) $collection->amount,
            'collection_date' => $collection->collection_date->toDateString(),
            'status' => $collection->status,
            'photo_url' => $collection->photoUrl(),
        ];
    }

    private function formatDetail(Collection $collection): array
    {
        return [
            'id' => $collection->id,
            'receipt_no' => $collection->receipt_no,
            'dealer' => $collection->dealer === null ? null : [
                'id' => $collection->dealer->id,
                'firm_name' => $collection->dealer->firm_name,
                'owner_name' => $collection->dealer->owner_name,
                'village' => $collection->dealer->village,
                'mobile' => $collection->dealer->mobile,
            ],
            'dealer_name' => $collection->dealer?->firm_name ?? '-',
            'amount' => (float) $collection->amount,
            'collection_date' => $collection->collection_date->toDateString(),
            'photo_url' => $collection->photoUrl(),
            'employee_remarks' => $collection->remarks,
            'admin_remark' => $collection->admin_remark,
            'status' => $collection->status,
            'status_label' => $this->statusLabel($collection->status),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            Collection::STATUS_PENDING => 'Pending Verification',
            Collection::STATUS_RECEIVED => 'Received',
            Collection::STATUS_NOT_RECEIVED => 'Not Received',
            default => Collection::statusLabels()[$status] ?? $status,
        };
    }
}
