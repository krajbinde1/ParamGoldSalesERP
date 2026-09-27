<?php

namespace App\Filament\Resources\CreditNotes\Pages;

use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Models\CreditNote;
use App\Models\User;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCreditNotes extends ListRecords
{
    protected static string $resource = CreditNoteResource::class;

    public function getDefaultActiveTab(): string|int|null
    {
        $user = auth()->user();
        if ($user instanceof User
            && ! $user->isAdminUser()
            && ! $user->isDirectorUser()
            && ($user->hasProductionManagerJobRole() || $user->hasProductionSupervisorJobRole() || $user->hasRole(\App\Enums\UserRole::ProductionSupervisor))) {
            return 'pending_production';
        }

        return 'pending_approval';
    }

    public function getTabs(): array
    {
        $count = fn (string $status): int => CreditNoteResource::getEloquentQuery()
            ->where('status', $status)
            ->count();

        return [
            'pending_approval' => Tab::make('Pending Approval')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', CreditNote::STATUS_PENDING_APPROVAL))
                ->badge(fn (): int => $count(CreditNote::STATUS_PENDING_APPROVAL)),
            'pending_production' => Tab::make('Pending Production')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', CreditNote::STATUS_PENDING_PRODUCTION_APPROVAL))
                ->badge(fn (): int => $count(CreditNote::STATUS_PENDING_PRODUCTION_APPROVAL)),
            'approved' => Tab::make('Approved')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', CreditNote::STATUS_APPROVED))
                ->badge(fn (): int => $count(CreditNote::STATUS_APPROVED)),
            'completed' => Tab::make('Completed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', CreditNote::STATUS_COMPLETED))
                ->badge(fn (): int => $count(CreditNote::STATUS_COMPLETED)),
            'rejected' => Tab::make('Rejected')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', CreditNote::STATUS_REJECTED))
                ->badge(fn (): int => $count(CreditNote::STATUS_REJECTED)),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
