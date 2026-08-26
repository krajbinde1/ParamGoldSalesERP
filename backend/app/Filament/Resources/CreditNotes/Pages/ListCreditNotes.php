<?php

namespace App\Filament\Resources\CreditNotes\Pages;

use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Models\CreditNote;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCreditNotes extends ListRecords
{
    protected static string $resource = CreditNoteResource::class;

    public function getTabs(): array
    {
        return [
            'pending_approval' => Tab::make('Pending Approval')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', CreditNote::STATUS_PENDING_APPROVAL))
                ->badge(fn (): int => CreditNote::query()->where('status', CreditNote::STATUS_PENDING_APPROVAL)->count()),
            'approved' => Tab::make('Approved')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', CreditNote::STATUS_APPROVED))
                ->badge(fn (): int => CreditNote::query()->where('status', CreditNote::STATUS_APPROVED)->count()),
            'completed' => Tab::make('Completed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', CreditNote::STATUS_COMPLETED))
                ->badge(fn (): int => CreditNote::query()->where('status', CreditNote::STATUS_COMPLETED)->count()),
            'rejected' => Tab::make('Rejected')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', CreditNote::STATUS_REJECTED))
                ->badge(fn (): int => CreditNote::query()->where('status', CreditNote::STATUS_REJECTED)->count()),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
