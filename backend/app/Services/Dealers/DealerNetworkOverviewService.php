<?php

namespace App\Services\Dealers;

use App\Models\Dealer;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class DealerNetworkOverviewService
{
    public const TOP_TALUKAS_LIMIT = 15;

    /**
     * @param  array{
     *     state?: ?string,
     *     district?: ?string,
     *     taluka?: ?string,
     *     employee_id?: ?int,
     *     dealer_type?: ?string
     * }  $filters
     * @return array<string, mixed>
     */
    public function overview(User $user, array $filters): array
    {
        $filters = $this->normalizeFilters($filters);

        $summaryRow = $this->filteredQuery($user, $filters)
            ->toBase()
            ->selectRaw('COUNT(*) as total_dealers')
            ->selectRaw("COUNT(DISTINCT CASE WHEN TRIM(COALESCE(district, '')) = '' THEN NULL ELSE district END) as total_districts")
            ->selectRaw("COUNT(DISTINCT CASE WHEN TRIM(COALESCE(taluka, '')) = '' THEN NULL ELSE taluka END) as total_talukas")
            ->selectRaw("COUNT(DISTINCT CASE WHEN TRIM(COALESCE(village, '')) = '' THEN NULL ELSE village END) as total_villages")
            ->first();

        $districtRows = $this->groupCounts(
            $this->filteredQuery($user, $filters, except: ['district', 'taluka']),
            'district',
        );

        $talukaQuery = $this->filteredQuery($user, $filters, except: ['taluka'])
            ->whereNotNull('taluka')
            ->whereRaw("TRIM(taluka) != ''");

        $talukaRows = $talukaQuery
            ->toBase()
            ->selectRaw('taluka as name')
            ->selectRaw('district')
            ->selectRaw('COUNT(*) as dealer_count')
            ->groupBy('taluka', 'district')
            ->orderByDesc('dealer_count')
            ->orderBy('taluka')
            ->when($filters['district'] === null, fn ($query) => $query->limit(self::TOP_TALUKAS_LIMIT))
            ->get()
            ->map(fn (object $row): array => [
                'name' => (string) $row->name,
                'district' => filled($row->district) ? (string) $row->district : null,
                'count' => (int) $row->dealer_count,
            ])
            ->values()
            ->all();

        $areaRows = $this->filteredQuery($user, $filters, except: ['district', 'taluka'])
            ->whereNotNull('district')
            ->whereRaw("TRIM(district) != ''")
            ->toBase()
            ->selectRaw('district as name')
            ->selectRaw('COUNT(*) as dealer_count')
            ->selectRaw("COUNT(DISTINCT CASE WHEN TRIM(COALESCE(taluka, '')) = '' THEN NULL ELSE taluka END) as taluka_count")
            ->selectRaw("COUNT(DISTINCT CASE WHEN TRIM(COALESCE(village, '')) = '' THEN NULL ELSE village END) as village_count")
            ->groupBy('district')
            ->orderByDesc('dealer_count')
            ->orderBy('district')
            ->get()
            ->map(fn (object $row): array => [
                'name' => (string) $row->name,
                'dealer_count' => (int) $row->dealer_count,
                'taluka_count' => (int) $row->taluka_count,
                'village_count' => (int) $row->village_count,
            ])
            ->values()
            ->all();

        $markers = $this->markers($user, $filters);
        $hasMappableDealers = $this->baseQuery($user)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('latitude', '!=', 0)
            ->where('longitude', '!=', 0)
            ->exists();

        return [
            'summary' => [
                'total_dealers' => (int) ($summaryRow->total_dealers ?? 0),
                'total_districts' => (int) ($summaryRow->total_districts ?? 0),
                'total_talukas' => (int) ($summaryRow->total_talukas ?? 0),
                'total_villages' => (int) ($summaryRow->total_villages ?? 0),
            ],
            'districts' => $districtRows,
            'talukas' => $talukaRows,
            'areas' => $areaRows,
            'markers' => $markers,
            'has_mappable_dealers' => $hasMappableDealers,
            'filter_options' => $this->filterOptions($user, $filters),
            'talukas_are_top_overall' => $filters['district'] === null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function applyToQuery(Builder $query, array $filters): Builder
    {
        $filters = $this->normalizeFilters($filters);

        return $this->constrain($query, $filters);
    }

    /**
     * @param  list<string>  $except
     * @param  array<string, mixed>  $filters
     * @return Builder<Dealer>
     */
    public function filteredQuery(User $user, array $filters, array $except = []): Builder
    {
        $filters = $this->normalizeFilters($filters);
        foreach ($except as $key) {
            $filters[$key] = null;
        }

        return $this->constrain($this->baseQuery($user), $filters);
    }

    /**
     * @return Builder<Dealer>
     */
    public function baseQuery(User $user): Builder
    {
        $query = Dealer::query();
        app(DealerAccessService::class)->scopeVisibleTo($query, $user);

        return $query;
    }

    /**
     * @param  Builder<Dealer>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Dealer>
     */
    private function constrain(Builder $query, array $filters): Builder
    {
        if (filled($filters['state'])) {
            $query->where('state', $filters['state']);
        }

        if (filled($filters['district'])) {
            $query->where('district', $filters['district']);
        }

        if (filled($filters['taluka'])) {
            $query->where('taluka', $filters['taluka']);
        }

        if (filled($filters['employee_id'])) {
            $query->where('assigned_employee_id', (int) $filters['employee_id']);
        }

        if (filled($filters['dealer_type'])) {
            $query->where('dealer_type', $filters['dealer_type']);
        }

        return $query;
    }

    /**
     * @param  Builder<Dealer>  $query
     * @return list<array{name: string, count: int}>
     */
    private function groupCounts(Builder $query, string $column): array
    {
        return $query
            ->whereNotNull($column)
            ->whereRaw("TRIM({$column}) != ''")
            ->toBase()
            ->selectRaw("{$column} as name")
            ->selectRaw('COUNT(*) as dealer_count')
            ->groupBy($column)
            ->orderByDesc('dealer_count')
            ->orderBy($column)
            ->get()
            ->map(fn (object $row): array => [
                'name' => (string) $row->name,
                'count' => (int) $row->dealer_count,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function markers(User $user, array $filters): array
    {
        return $this->filteredQuery($user, $filters)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('latitude', '!=', 0)
            ->where('longitude', '!=', 0)
            ->with(['assignedEmployee:id,full_name,employee_code'])
            ->orderBy('firm_name')
            ->get([
                'id',
                'dealer_code',
                'firm_name',
                'district',
                'taluka',
                'village',
                'latitude',
                'longitude',
                'assigned_employee_id',
            ])
            ->map(fn (Dealer $dealer): array => [
                'id' => (int) $dealer->id,
                'dealer_code' => $dealer->dealer_code,
                'firm_name' => $dealer->firm_name,
                'district' => $dealer->district,
                'taluka' => $dealer->taluka,
                'village' => $dealer->village,
                'latitude' => (float) $dealer->latitude,
                'longitude' => (float) $dealer->longitude,
                'assigned_employee' => $dealer->assignedEmployee?->assignmentLabel(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     states: array<string, string>,
     *     districts: array<string, string>,
     *     talukas: array<string, string>,
     *     employees: array<int, string>,
     *     dealer_types: array<string, string>
     * }
     */
    private function filterOptions(User $user, array $filters): array
    {
        $stateQuery = $this->filteredQuery($user, $filters, except: ['state', 'district', 'taluka']);
        $districtQuery = $this->filteredQuery($user, $filters, except: ['district', 'taluka']);
        $talukaQuery = $this->filteredQuery($user, $filters, except: ['taluka']);
        $employeeQuery = $this->filteredQuery($user, $filters, except: ['employee_id']);

        $employeeIds = $employeeQuery
            ->clone()
            ->whereNotNull('assigned_employee_id')
            ->distinct()
            ->pluck('assigned_employee_id');

        $employees = Employee::query()
            ->whereIn('id', $employeeIds)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'employee_code'])
            ->mapWithKeys(fn (Employee $employee): array => [
                $employee->id => $employee->assignmentLabel(),
            ])
            ->all();

        return [
            'states' => $this->distinctList($stateQuery, 'state'),
            'districts' => $this->distinctList($districtQuery, 'district'),
            'talukas' => $this->distinctList($talukaQuery, 'taluka'),
            'employees' => $employees,
            'dealer_types' => [
                'Distributor' => 'Distributor',
                'Retailer' => 'Retailer',
                'Wholesaler' => 'Wholesaler',
            ],
        ];
    }

    /**
     * @param  Builder<Dealer>  $query
     * @return array<string, string>
     */
    private function distinctList(Builder $query, string $column): array
    {
        return $query
            ->whereNotNull($column)
            ->whereRaw("TRIM({$column}) != ''")
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{state: ?string, district: ?string, taluka: ?string, employee_id: ?int, dealer_type: ?string}
     */
    private function normalizeFilters(array $filters): array
    {
        $string = static fn (mixed $value): ?string => filled($value) ? trim((string) $value) : null;

        return [
            'state' => $string($filters['state'] ?? null),
            'district' => $string($filters['district'] ?? null),
            'taluka' => $string($filters['taluka'] ?? null),
            'employee_id' => filled($filters['employee_id'] ?? null) ? (int) $filters['employee_id'] : null,
            'dealer_type' => $string($filters['dealer_type'] ?? null),
        ];
    }
}
