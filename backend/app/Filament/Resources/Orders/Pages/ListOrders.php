<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public function mount(): void
    {
        parent::mount();

        $status = request()->query('status');

        if (! in_array($status, [
            Order::STATUS_APPROVED,
            Order::STATUS_PENDING_FOR_BILLING,
            Order::STATUS_BILLED,
            Order::STATUS_DISPATCHED,
        ], true)) {
            return;
        }

        $this->tableFilters = [
            'status' => [
                'value' => $status,
            ],
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'pending_approval' => Tab::make('Pending Approval')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Order::STATUS_PENDING_APPROVAL))
                ->badge(fn (): int => Order::query()->where('status', Order::STATUS_PENDING_APPROVAL)->count()),
            'approved' => Tab::make('Approved')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Order::STATUS_APPROVED))
                ->badge(fn (): int => Order::query()->where('status', Order::STATUS_APPROVED)->count()),
            'pending_for_billing' => Tab::make('Pending for Billing')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Order::STATUS_PENDING_FOR_BILLING))
                ->badge(fn (): int => Order::query()->where('status', Order::STATUS_PENDING_FOR_BILLING)->count()),
            'billed' => Tab::make('Billed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Order::STATUS_BILLED))
                ->badge(fn (): int => Order::query()->where('status', Order::STATUS_BILLED)->count()),
            'dispatched' => Tab::make('Dispatched')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Order::STATUS_DISPATCHED)),
            'rejected' => Tab::make('Rejected')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Order::STATUS_REJECTED)),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool => ! auth()->user()?->hasOrdersOnlyFilamentAccess()),
        ];
    }
}
