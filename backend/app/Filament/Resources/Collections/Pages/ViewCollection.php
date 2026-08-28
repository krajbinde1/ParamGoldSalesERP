<?php

namespace App\Filament\Resources\Collections\Pages;

use App\Filament\Resources\Collections\Actions\EditCollectionStatusAction;
use App\Filament\Resources\Collections\CollectionResource;
use App\Models\Collection;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

class ViewCollection extends ViewRecord
{
    protected static string $resource = CollectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditCollectionStatusAction::make(),
            Action::make('markReceived')
                ->label('Mark Received')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->getRecord()->canTransitionTo(Collection::STATUS_RECEIVED))
                ->action(fn () => $this->getRecord()->transitionTo(Collection::STATUS_RECEIVED)),
            Action::make('markNotReceived')
                ->label('Mark Not Received')
                ->color('danger')
                ->visible(fn (): bool => $this->getRecord()->canTransitionTo(Collection::STATUS_NOT_RECEIVED))
                ->form([
                    Textarea::make('admin_remark')
                        ->label('Admin Remark')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    $this->getRecord()->transitionTo(
                        Collection::STATUS_NOT_RECEIVED,
                        ['admin_remark' => $data['admin_remark']],
                    );
                }),
        ];
    }
}
