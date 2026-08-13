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

    /**
     * Status keys that map 1:1 to list tabs.
     *
     * @var list<string>
     */
    private const STATUS_TABS = [
        Order::STATUS_APPROVED,
        Order::STATUS_PENDING_FOR_BILLING,
        Order::STATUS_BILLED,
        Order::STATUS_DISPATCHED,
        Order::STATUS_PENDING_APPROVAL,
        Order::STATUS_REJECTED,
    ];

    public function mount(): void
    {
        parent::mount();

        // Prefer explicit tab/status deep-links over a sticky status SelectFilter.
        // Combining filters[status]=X with a different active tab produces:
        //   WHERE status = {tab} AND status = {filter}  → empty result set.
        $requested = request()->query('tab')
            ?? request()->query('status')
            ?? data_get($this->tableFilters, 'status.value')
            ?? data_get($this->tableFilters, 'status');

        if (! is_string($requested) || ! in_array($requested, self::STATUS_TABS, true)) {
            return;
        }

        $this->activeTab = $requested;
        $this->clearStatusTableFilter();
    }

    public function updatedActiveTab(): void
    {
        // Switching tabs must drop any leftover status filter from dashboard links
        // (e.g. filters[status]=approved), or the Billed/Dispatched tabs stay empty.
        $this->clearStatusTableFilter();

        $this->resetPage();

        $this->cachedDefaultTableColumnState = null;

        $this->applyTableColumnManager();
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
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Order::STATUS_DISPATCHED))
                ->badge(fn (): int => Order::query()->where('status', Order::STATUS_DISPATCHED)->count()),
            'rejected' => Tab::make('Rejected')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Order::STATUS_REJECTED))
                ->badge(fn (): int => Order::query()->where('status', Order::STATUS_REJECTED)->count()),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool => ! auth()->user()?->hasOrdersOnlyFilamentAccess()),
        ];
    }

    private function clearStatusTableFilter(): void
    {
        if (! is_array($this->tableFilters) || ! array_key_exists('status', $this->tableFilters)) {
            return;
        }

        unset($this->tableFilters['status']);

        if ($this->tableFilters === []) {
            $this->tableFilters = null;
        }
    }
}
