<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Farmers\FarmerResource;
use App\Models\Crop;
use App\Models\Farmer;
use App\Models\FieldActivity;
use App\Models\MaharashtraDistrict;
use App\Models\MaharashtraTaluka;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FarmerInsights extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Employee Management';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Farmer Reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $title = 'Farmer Reports';

    protected static ?string $slug = 'farmer-reports';

    protected string $view = 'filament.pages.farmer-insights';

    public ?int $districtId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->usesAdminDirectorDashboard() || $user->isAdminUser());
    }

    /**
     * @return Collection<int, object>
     */
    public function districtRows(): Collection
    {
        return MaharashtraDistrict::query()
            ->withCount('talukas')
            ->orderBy('name')
            ->get()
            ->map(function (MaharashtraDistrict $district): object {
                return (object) [
                    'id' => $district->id,
                    'name' => $district->displayName(),
                    'farmers' => Farmer::query()->where('district_id', $district->id)->count(),
                    'activities' => FieldActivity::query()->where('district_id', $district->id)->count(),
                ];
            })
            ->filter(fn (object $row): bool => $row->farmers > 0 || $row->activities > 0)
            ->values();
    }

    /**
     * @return Collection<int, object>
     */
    public function talukaRows(): Collection
    {
        if ($this->districtId === null) {
            return collect();
        }

        return MaharashtraTaluka::query()
            ->where('district_id', $this->districtId)
            ->orderBy('name')
            ->get()
            ->map(function (MaharashtraTaluka $taluka): object {
                return (object) [
                    'id' => $taluka->id,
                    'name' => $taluka->name,
                    'farmers' => Farmer::query()->where('taluka_id', $taluka->id)->count(),
                    'activities' => FieldActivity::query()->where('taluka_id', $taluka->id)->count(),
                ];
            })
            ->filter(fn (object $row): bool => $row->farmers > 0 || $row->activities > 0)
            ->values();
    }

    /**
     * @return Collection<int, object>
     */
    public function cropRows(): Collection
    {
        return Crop::query()
            ->withCount('fieldActivities')
            ->orderByDesc('field_activities_count')
            ->orderBy('name')
            ->get()
            ->filter(fn (Crop $crop): bool => $crop->field_activities_count > 0)
            ->map(fn (Crop $crop): object => (object) [
                'name' => $crop->name,
                'farmers' => Farmer::query()->whereHas('fieldActivities', fn ($q) => $q->where('crop_id', $crop->id))->count(),
                'activities' => $crop->field_activities_count,
            ])
            ->values();
    }

    /**
     * @return Collection<int, object>
     */
    public function productRows(): Collection
    {
        return DB::table('field_activity_recommendations as r')
            ->join('products as p', 'p.id', '=', 'r.product_id')
            ->join('field_activities as fa', 'fa.id', '=', 'r.field_activity_id')
            ->whereNull('fa.deleted_at')
            ->selectRaw('p.product_name as name, COUNT(*) as recommendations, COUNT(DISTINCT fa.farmer_id) as farmers')
            ->groupBy('p.id', 'p.product_name')
            ->orderByDesc('recommendations')
            ->get();
    }

    public function selectDistrict(int $districtId): void
    {
        $this->districtId = $districtId;
    }

    public function farmersIndexUrl(?int $districtId = null, ?int $talukaId = null): string
    {
        $params = [];
        if ($districtId !== null) {
            $params['filters']['district_id']['value'] = $districtId;
        }
        if ($talukaId !== null) {
            $params['filters']['taluka_id']['value'] = $talukaId;
        }

        return FarmerResource::getUrl('index', $params);
    }

    public function selectedDistrictName(): ?string
    {
        if ($this->districtId === null) {
            return null;
        }

        return MaharashtraDistrict::query()->find($this->districtId)?->displayName();
    }
}
