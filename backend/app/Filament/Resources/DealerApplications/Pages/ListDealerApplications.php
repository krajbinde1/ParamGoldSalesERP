<?php

namespace App\Filament\Resources\DealerApplications\Pages;

use App\Filament\Resources\DealerApplications\DealerApplicationResource;
use App\Filament\Resources\DealerApplications\Widgets\DealerApplicationStats;
use App\Models\DealerApplication;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListDealerApplications extends ListRecords
{
    protected static string $resource = DealerApplicationResource::class;

    public function mount(): void
    {
        parent::mount();
        $this->activeTab = 'pending_admin';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            DealerApplicationStats::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return [
            'default' => 1,
            'md' => 2,
            'xl' => 4,
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'pending_manager' => Tab::make('Pending Manager')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', DealerApplication::STATUS_PENDING_MANAGER))
                ->badge(fn (): int => DealerApplication::query()->where('status', DealerApplication::STATUS_PENDING_MANAGER)->count()),
            'pending_admin' => Tab::make('Pending Admin')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', DealerApplication::STATUS_PENDING_ADMIN))
                ->badge(fn (): int => DealerApplication::query()->where('status', DealerApplication::STATUS_PENDING_ADMIN)->count()),
            'correction_required' => Tab::make('Correction Required')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', DealerApplication::STATUS_CORRECTION_REQUIRED))
                ->badge(fn (): int => DealerApplication::query()->where('status', DealerApplication::STATUS_CORRECTION_REQUIRED)->count()),
            'approved' => Tab::make('Approved')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', DealerApplication::STATUS_APPROVED)),
            'rejected' => Tab::make('Rejected')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', DealerApplication::STATUS_REJECTED)),
        ];
    }
}
