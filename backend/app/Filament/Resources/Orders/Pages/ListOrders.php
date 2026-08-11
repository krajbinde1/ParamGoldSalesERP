<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public function mount(): void
    {
        parent::mount();

        $status = request()->query('status');

        if (! in_array($status, ['approved', Order::STATUS_BILLED, Order::STATUS_DISPATCHED], true)) {
            return;
        }

        $this->tableFilters = [
            'status' => [
                'value' => $status,
            ],
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
