<?php

namespace App\Filament\Resources\OrderEditPermissionRequests\Pages;

use App\Filament\Resources\OrderEditPermissionRequests\OrderEditPermissionRequestResource;
use App\Models\OrderEditPermissionRequest;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrderEditPermissionRequests extends ListRecords
{
    protected static string $resource = OrderEditPermissionRequestResource::class;

    public function mount(): void
    {
        parent::mount();

        if (blank($this->activeTab)) {
            $this->activeTab = 'pending';
        }
    }

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderEditPermissionRequest::STATUS_PENDING))
                ->badge(fn (): int => OrderEditPermissionRequest::query()->pending()->count()),
            'approved' => Tab::make('Approved')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', OrderEditPermissionRequest::unlockedStatuses())),
            'used' => Tab::make('Used')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderEditPermissionRequest::STATUS_USED)),
            'rejected' => Tab::make('Rejected')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderEditPermissionRequest::STATUS_REJECTED)),
            'all' => Tab::make('All'),
        ];
    }
}
